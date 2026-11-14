use std::{
    collections::HashMap,
    fs::File,
    future::Future,
    io::Read,
    path::{Path as FsPath, PathBuf},
    pin::Pin,
    sync::{Arc, Mutex},
    time::{Duration, SystemTime, UNIX_EPOCH},
};

use axum::{
    body::{to_bytes, Body},
    extract::{
        ws::{Message, WebSocket, WebSocketUpgrade},
        Path, Query, Request, State,
    },
    http::StatusCode,
    middleware::{self, Next},
    response::{IntoResponse, Response},
    routing::{get, post, put},
    Json, Router,
};
use bollard::{
    errors::Error as DockerError,
    exec::{CreateExecOptions, StartExecResults},
    models::{
        ContainerCreateBody, ContainerInspectResponse, ContainerState, ContainerStateStatusEnum, HealthStatusEnum,
        HostConfig,
    },
    query_parameters::{
        CreateContainerOptionsBuilder, CreateImageOptionsBuilder, InspectContainerOptions, KillContainerOptions,
        ListContainersOptionsBuilder, LogsOptionsBuilder, RemoveContainerOptions, RestartContainerOptionsBuilder,
        StartContainerOptions, StatsOptionsBuilder, StopContainerOptionsBuilder,
    },
    Docker,
};
use flate2::read::GzDecoder;
use futures_util::{stream, Stream, StreamExt};
use hmac::{Hmac, KeyInit, Mac};
use serde::Deserialize;
use serde_json::{json, Value};
use sha2::Sha256;

// Every action is refused on containers without this label.
const MANAGED_LABEL: &str = "mcpanel.managed";
const IMAGE: &str = "itzg/minecraft-server";
// Shared with mc-router (compose.yaml), which routes players to servers by hostname.
const NETWORK: &str = "minecraft";
const JAVA_VERSIONS: [u8; 4] = [8, 17, 21, 25];
// Long enough for the server to save the world on SIGTERM.
const STOP_TIMEOUT_SECS: i32 = 60;
const MAX_SKEW_SECS: u64 = 60;
const CONSOLE_BACKLOG_LINES: &str = "200";
const MAX_COMMAND_CHARS: usize = 500;
// Also bounds what a gzipped log may expand to.
const MAX_LOG_BYTES: u64 = 20 * 1024 * 1024;
// ponytail: bodies are buffered to verify the signature; file uploads will need a streamed scheme.
const MAX_BODY_BYTES: usize = 1024 * 1024;

enum Task {
    Busy(&'static str),
    Failed(String),
}

// ponytail: running and failed tasks live in memory, so a service restart forgets them; persist if that hurts.
type Tasks = Arc<Mutex<HashMap<String, Task>>>;

#[derive(Clone)]
struct AppState {
    docker: Docker,
    token: Arc<[u8]>,
    data_dir: Arc<str>,
    tasks: Tasks,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct ServerConfig {
    hostname: String,
    java_version: u8,
    server_type: String,
    mc_version: String,
    memory_mb: u32,
    eula: bool,
    motd: String,
    difficulty: String,
    mode: String,
    max_players: u16,
    #[serde(default)]
    start: bool,
}

impl ServerConfig {
    // The panel validates too; this is the last gate before values reach a container definition.
    fn validate(&self) -> Result<(), &'static str> {
        let hostname_ok = !self.hostname.is_empty()
            && self.hostname.len() <= 253
            && self.hostname.bytes().all(|b| b.is_ascii_lowercase() || b.is_ascii_digit() || b == b'.' || b == b'-');
        let version_ok = self.mc_version == "LATEST"
            || (!self.mc_version.is_empty()
                && self.mc_version.len() <= 16
                && self.mc_version.bytes().all(|b| b.is_ascii_digit() || b == b'.'));

        if !hostname_ok {
            Err("hostname")
        } else if !JAVA_VERSIONS.contains(&self.java_version) {
            Err("java_version")
        } else if !["VANILLA", "PAPER"].contains(&self.server_type.as_str()) {
            Err("server_type")
        } else if !version_ok {
            Err("mc_version")
        } else if !(512..=65536).contains(&self.memory_mb) {
            Err("memory_mb")
        } else if !self.eula {
            Err("eula")
        } else if self.motd.chars().count() > 100 || self.motd.chars().any(char::is_control) {
            Err("motd")
        } else if !["peaceful", "easy", "normal", "hard"].contains(&self.difficulty.as_str()) {
            Err("difficulty")
        } else if !["survival", "creative", "adventure", "spectator"].contains(&self.mode.as_str()) {
            Err("mode")
        } else if !(1..=1000).contains(&self.max_players) {
            Err("max_players")
        } else {
            Ok(())
        }
    }

    fn env(&self) -> Vec<String> {
        vec![
            "EULA=TRUE".into(),
            format!("TYPE={}", self.server_type),
            format!("VERSION={}", self.mc_version),
            // The heap gets three quarters of the container limit; the JVM needs the rest for itself.
            format!("MEMORY={}M", self.memory_mb / 4 * 3),
            format!("MOTD={}", self.motd),
            format!("DIFFICULTY={}", self.difficulty),
            format!("MODE={}", self.mode),
            format!("MAX_PLAYERS={}", self.max_players),
        ]
    }
}

// A slug names the container and the data folder, so it must never carry a path or option.
fn valid_slug(slug: &str) -> bool {
    (1..=63).contains(&slug.len())
        && slug.bytes().all(|b| b.is_ascii_lowercase() || b.is_ascii_digit() || b == b'-')
        && !slug.starts_with('-')
        && !slug.ends_with('-')
}

fn container_name(slug: &str) -> String {
    format!("mc-{slug}")
}

fn describe(state: &ContainerState) -> (&'static str, Option<String>) {
    use ContainerStateStatusEnum::{DEAD, EXITED, RESTARTING, RUNNING, STOPPING};

    match state.status {
        Some(RUNNING) => match state.health.as_ref().and_then(|health| health.status) {
            Some(HealthStatusEnum::STARTING | HealthStatusEnum::UNHEALTHY) => ("starting", None),
            _ => ("running", None),
        },
        Some(RESTARTING) => ("starting", None),
        Some(STOPPING) => ("stopping", None),
        Some(EXITED | DEAD) if state.oom_killed == Some(true) => {
            ("error", Some("Ran out of memory. Raise the memory in Configuration.".into()))
        }
        // 0 is a clean stop, 137 and 143 are the kill and stop signals the panel sends itself.
        Some(EXITED | DEAD) => match state.exit_code.unwrap_or(0) {
            0 | 137 | 143 => ("offline", None),
            code => ("error", Some(format!("Exited with code {code}. The logs say why."))),
        },
        _ => ("offline", None),
    }
}

fn is_not_found(error: &DockerError) -> bool {
    matches!(error, DockerError::DockerResponseServerError { status_code: 404, .. })
}

// None when there is no such container; an error when one exists that the panel does not manage.
async fn managed(docker: &Docker, slug: &str) -> Result<Option<ContainerInspectResponse>, StatusCode> {
    match docker.inspect_container(&container_name(slug), None::<InspectContainerOptions>).await {
        Ok(container) => {
            let labels = container.config.as_ref().and_then(|config| config.labels.as_ref());
            if labels.and_then(|labels| labels.get(MANAGED_LABEL)).map(String::as_str) == Some("true") {
                Ok(Some(container))
            } else {
                Err(StatusCode::FORBIDDEN)
            }
        }
        Err(error) if is_not_found(&error) => Ok(None),
        Err(error) => {
            eprintln!("docker inspect failed: {error}");
            Err(StatusCode::BAD_GATEWAY)
        }
    }
}

// Runs slow Docker work in the background so the panel's request returns at once.
fn run_task<F>(tasks: &Tasks, slug: String, label: &'static str, work: F) -> StatusCode
where
    F: Future<Output = Result<(), DockerError>> + Send + 'static,
{
    {
        let mut tasks = tasks.lock().unwrap();
        if matches!(tasks.get(&slug), Some(Task::Busy(_))) {
            return StatusCode::CONFLICT;
        }
        tasks.insert(slug.clone(), Task::Busy(label));
    }

    let tasks = tasks.clone();
    tokio::spawn(async move {
        let result = work.await;
        let mut tasks = tasks.lock().unwrap();
        match result {
            Ok(()) => {
                tasks.remove(&slug);
            }
            Err(error) => {
                eprintln!("{label} failed for {slug}: {error}");
                tasks.insert(slug, Task::Failed(error.to_string()));
            }
        }
    });

    StatusCode::ACCEPTED
}

async fn stop_and_remove(docker: &Docker, name: &str) -> Result<(), DockerError> {
    let stop = StopContainerOptionsBuilder::default().t(STOP_TIMEOUT_SECS).build();
    // Docker answers 304 for an already stopped container, which bollard treats as success.
    docker.stop_container(name, Some(stop)).await?;
    docker.remove_container(name, None::<RemoveContainerOptions>).await
}

async fn apply(docker: Docker, data_dir: Arc<str>, slug: String, config: ServerConfig, existing: Option<bool>) -> Result<(), DockerError> {
    let image = format!("{IMAGE}:java{}", config.java_version);
    // ponytail: pulled only when missing, so image updates need a manual pull; add an update action later.
    if docker.inspect_image(&image).await.is_err() {
        let pull = CreateImageOptionsBuilder::default().from_image(&image).build();
        let mut progress = docker.create_image(Some(pull), None, None);
        while let Some(step) = progress.next().await {
            step?;
        }
    }

    let name = container_name(&slug);
    if existing.is_some() {
        stop_and_remove(&docker, &name).await?;
    }

    let body = ContainerCreateBody {
        image: Some(image),
        env: Some(config.env()),
        labels: Some(HashMap::from([
            (MANAGED_LABEL.to_owned(), "true".to_owned()),
            ("mc-router.host".to_owned(), config.hostname.clone()),
        ])),
        host_config: Some(HostConfig {
            binds: Some(vec![format!("{data_dir}/{slug}:/data")]),
            memory: Some(i64::from(config.memory_mb) * 1024 * 1024),
            network_mode: Some(NETWORK.to_owned()),
            ..Default::default()
        }),
        ..Default::default()
    };
    docker.create_container(Some(CreateContainerOptionsBuilder::default().name(&name).build()), body).await?;

    if config.start || existing == Some(true) {
        docker.start_container(&name, None::<StartContainerOptions>).await?;
    }

    Ok(())
}

async fn put_server(
    State(state): State<AppState>,
    Path(slug): Path<String>,
    Json(config): Json<ServerConfig>,
) -> Result<StatusCode, (StatusCode, String)> {
    if !valid_slug(&slug) {
        return Err((StatusCode::UNPROCESSABLE_ENTITY, "slug".into()));
    }
    config.validate().map_err(|field| (StatusCode::UNPROCESSABLE_ENTITY, field.into()))?;

    let existing = managed(&state.docker, &slug)
        .await
        .map_err(|status| (status, String::new()))?
        .map(|container| container.state.and_then(|state| state.running).unwrap_or(false));
    let work = apply(state.docker.clone(), state.data_dir.clone(), slug.clone(), config, existing);

    Ok(run_task(&state.tasks, slug, "installing", work))
}

async fn power(State(state): State<AppState>, Path((slug, action)): Path<(String, String)>) -> Result<StatusCode, StatusCode> {
    if !valid_slug(&slug) || managed(&state.docker, &slug).await?.is_none() {
        return Err(StatusCode::NOT_FOUND);
    }

    let docker = state.docker.clone();
    let name = container_name(&slug);
    let stop = StopContainerOptionsBuilder::default().t(STOP_TIMEOUT_SECS).build();
    let restart = RestartContainerOptionsBuilder::default().t(STOP_TIMEOUT_SECS).build();

    Ok(match action.as_str() {
        "start" => run_task(&state.tasks, slug, "starting", async move {
            docker.start_container(&name, None::<StartContainerOptions>).await
        }),
        "stop" => run_task(&state.tasks, slug, "stopping", async move { docker.stop_container(&name, Some(stop)).await }),
        "restart" => run_task(&state.tasks, slug, "restarting", async move {
            docker.restart_container(&name, Some(restart)).await
        }),
        // Kill must work while a stop is still waiting, so it skips the task queue.
        "kill" => match docker.kill_container(&name, None::<KillContainerOptions>).await {
            Ok(()) => StatusCode::ACCEPTED,
            Err(error) => {
                eprintln!("kill failed for {slug}: {error}");
                StatusCode::CONFLICT
            }
        },
        _ => return Err(StatusCode::NOT_FOUND),
    })
}

// Removes the container only; the data folder stays on disk.
async fn delete_server(State(state): State<AppState>, Path(slug): Path<String>) -> Result<StatusCode, StatusCode> {
    if !valid_slug(&slug) {
        return Err(StatusCode::NOT_FOUND);
    }
    if managed(&state.docker, &slug).await?.is_none() {
        state.tasks.lock().unwrap().remove(&slug);
        return Ok(StatusCode::NO_CONTENT);
    }

    let docker = state.docker.clone();
    let name = container_name(&slug);

    Ok(run_task(&state.tasks, slug, "deleting", async move { stop_and_remove(&docker, &name).await }))
}

async fn list_servers(State(state): State<AppState>) -> Result<Json<Value>, StatusCode> {
    let label = format!("{MANAGED_LABEL}=true");
    let filters = HashMap::from([("label", vec![label.as_str()])]);
    let options = ListContainersOptionsBuilder::default().all(true).filters(&filters).build();
    let containers = state.docker.list_containers(Some(options)).await.map_err(|error| {
        eprintln!("docker list failed: {error}");
        StatusCode::BAD_GATEWAY
    })?;

    let mut servers: HashMap<String, (String, Option<String>)> = HashMap::new();
    for container in containers {
        let names = container.names.unwrap_or_default();
        let Some(slug) = names.first().and_then(|name| name.strip_prefix("/mc-")) else {
            continue;
        };
        // One inspect per server: the list call has no exit code or health.
        if let Ok(Some(inspected)) = managed(&state.docker, slug).await {
            let (label, detail) = describe(&inspected.state.unwrap_or_default());
            servers.insert(slug.to_owned(), (label.to_owned(), detail));
        }
    }

    for (slug, task) in state.tasks.lock().unwrap().iter() {
        let entry = match task {
            Task::Busy(label) => ((*label).to_owned(), None),
            Task::Failed(message) => ("error".to_owned(), Some(message.clone())),
        };
        servers.insert(slug.clone(), entry);
    }

    Ok(Json(Value::Array(
        servers
            .into_iter()
            .map(|(slug, (state, detail))| json!({ "slug": slug, "state": state, "detail": detail }))
            .collect(),
    )))
}

fn unix_now() -> u64 {
    SystemTime::now().duration_since(UNIX_EPOCH).map_or(0, |elapsed| elapsed.as_secs())
}

#[derive(Deserialize)]
struct ConsoleClaims {
    slug: String,
    exp: u64,
    write: bool,
}

// A console token is hex(json claims) + "." + hex(hmac of "console\n" + hex claims), minted by the panel.
fn console_claims(key: &[u8], token: &str, slug: &str, now: u64) -> Option<ConsoleClaims> {
    let (payload, signature) = token.split_once('.')?;
    let mut mac = Hmac::<Sha256>::new_from_slice(key).ok()?;
    mac.update(b"console\n");
    mac.update(payload.as_bytes());
    mac.verify_slice(&hex::decode(signature).ok()?).ok()?;

    let claims: ConsoleClaims = serde_json::from_slice(&hex::decode(payload).ok()?).ok()?;
    (claims.slug == slug && claims.exp >= now).then_some(claims)
}

type DockerStream<T> = Pin<Box<dyn Stream<Item = Result<T, DockerError>> + Send>>;

// Docker ends log and stats streams when the container stops; this attaches again so a session survives restarts.
fn reattaching<T: Send + 'static>(
    attach: impl Fn(bool) -> DockerStream<T> + Send + 'static,
) -> Pin<Box<dyn Stream<Item = T> + Send>> {
    Box::pin(stream::unfold((attach, None::<DockerStream<T>>, true), |(attach, mut current, mut first)| async move {
        loop {
            let source = current.get_or_insert_with(|| attach(first));
            first = false;
            match source.next().await {
                Some(Ok(item)) => return Some((item, (attach, current, first))),
                _ => {
                    current = None;
                    tokio::time::sleep(Duration::from_secs(2)).await;
                }
            }
        }
    }))
}

fn valid_command(command: &str) -> bool {
    // A leading dash would be read as an option by rcon-cli.
    !command.is_empty()
        && !command.starts_with('-')
        && command.chars().count() <= MAX_COMMAND_CHARS
        && !command.chars().any(char::is_control)
}

async fn run_command(docker: &Docker, name: &str, command: &str) -> String {
    let command = command.trim().trim_start_matches('/');
    if !valid_command(command) {
        return "That command cannot be sent.\n".into();
    }

    let exec = CreateExecOptions {
        cmd: Some(vec!["rcon-cli", command]),
        attach_stdout: Some(true),
        attach_stderr: Some(true),
        ..Default::default()
    };
    let started = match docker.create_exec(name, exec).await {
        Ok(created) => docker.start_exec(&created.id, None).await,
        Err(error) => Err(error),
    };

    match started {
        Ok(StartExecResults::Attached { mut output, .. }) => {
            let mut text = String::new();
            while let Some(Ok(chunk)) = output.next().await {
                text.push_str(&chunk.to_string());
            }
            text
        }
        Ok(StartExecResults::Detached) => String::new(),
        Err(_) => "The command could not be run. Is the server running?\n".into(),
    }
}

async fn console_session(docker: Docker, slug: String, write: bool, mut socket: WebSocket) {
    let name = container_name(&slug);
    let mut logs = reattaching({
        let (docker, name) = (docker.clone(), name.clone());
        move |first| {
            let options = LogsOptionsBuilder::default().follow(true).stdout(true).stderr(true);
            let options = if first {
                options.tail(CONSOLE_BACKLOG_LINES)
            } else {
                // Two seconds back covers the pause before attaching again.
                options.since(i32::try_from(unix_now()).unwrap_or(i32::MAX) - 2)
            };
            Box::pin(docker.logs(&name, Some(options.build())))
        }
    });
    let mut stats = reattaching({
        let (docker, name) = (docker.clone(), name.clone());
        move |_| Box::pin(docker.stats(&name, Some(StatsOptionsBuilder::default().stream(true).build())))
    });
    let mut previous_cpu: Option<(u64, u64)> = None;

    loop {
        let frame = tokio::select! {
            message = socket.recv() => match message {
                Some(Ok(Message::Text(command))) if write => {
                    json!({ "t": "out", "d": run_command(&docker, &name, command.as_str()).await })
                }
                Some(Ok(Message::Close(_))) | Some(Err(_)) | None => break,
                Some(Ok(_)) => continue,
            },
            Some(chunk) = logs.next() => json!({ "t": "log", "d": chunk.to_string() }),
            Some(sample) = stats.next() => {
                let cpu = sample.cpu_stats.unwrap_or_default();
                let now = (cpu.cpu_usage.and_then(|usage| usage.total_usage).unwrap_or(0), cpu.system_cpu_usage.unwrap_or(0));
                let percent = match previous_cpu.replace(now) {
                    Some((total, system)) if now.1 > system => {
                        now.0.saturating_sub(total) as f64 / (now.1 - system) as f64
                            * f64::from(cpu.online_cpus.unwrap_or(1))
                            * 100.0
                    }
                    _ => 0.0,
                };
                let memory = sample.memory_stats.unwrap_or_default();
                // Like "docker stats": file cache the kernel can drop is not counted as used.
                let cache = memory.stats.as_ref().and_then(|stats| stats.get("inactive_file")).copied().unwrap_or(0);

                json!({
                    "t": "stats",
                    "cpu": (percent * 10.0).round() / 10.0,
                    "memory": memory.usage.unwrap_or(0).saturating_sub(cache),
                    "memory_limit": memory.limit.unwrap_or(0),
                })
            }
        };

        if socket.send(Message::Text(frame.to_string().into())).await.is_err() {
            break;
        }
    }
}

// The token in the query is the credential: browsers cannot set headers on a WebSocket.
async fn console(
    State(state): State<AppState>,
    Path(slug): Path<String>,
    Query(query): Query<HashMap<String, String>>,
    upgrade: WebSocketUpgrade,
) -> Response {
    let token = query.get("token").map(String::as_str).unwrap_or_default();
    let Some(claims) = console_claims(&state.token, token, &slug, unix_now()) else {
        return StatusCode::UNAUTHORIZED.into_response();
    };
    if !valid_slug(&slug) {
        return StatusCode::NOT_FOUND.into_response();
    }
    if let Err(status) = managed(&state.docker, &slug).await {
        return status.into_response();
    }

    upgrade.on_upgrade(move |socket| console_session(state.docker, slug, claims.write, socket))
}

fn valid_log_name(name: &str) -> bool {
    (1..=120).contains(&name.len())
        && !name.starts_with('.')
        && (name.ends_with(".log") || name.ends_with(".log.gz"))
        && name.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'.' || b == b'-' || b == b'_')
}

fn logs_dir(state: &AppState, slug: &str) -> PathBuf {
    FsPath::new(&*state.data_dir).join(slug).join("logs")
}

async fn list_logs(State(state): State<AppState>, Path(slug): Path<String>) -> Result<Json<Value>, StatusCode> {
    if !valid_slug(&slug) {
        return Err(StatusCode::NOT_FOUND);
    }

    let mut files = Vec::new();
    // A server that never started has no logs folder yet.
    for entry in std::fs::read_dir(logs_dir(&state, &slug)).into_iter().flatten().flatten() {
        let name = entry.file_name().to_string_lossy().into_owned();
        // DirEntry metadata does not follow symlinks, so links are left out.
        let Ok(metadata) = entry.metadata() else { continue };
        if metadata.is_file() && valid_log_name(&name) {
            let modified = metadata.modified().ok().and_then(|time| time.duration_since(UNIX_EPOCH).ok());
            files.push((modified.map_or(0, |elapsed| elapsed.as_secs()), name, metadata.len()));
        }
    }
    files.sort_by(|a, b| b.cmp(a));

    Ok(Json(Value::Array(
        files
            .into_iter()
            .map(|(modified, name, size)| json!({ "name": name, "size": size, "modified": modified }))
            .collect(),
    )))
}

// ponytail: reads the whole file in the request task; fine for logs, stream it if they grow past MAX_LOG_BYTES.
async fn read_log(
    State(state): State<AppState>,
    Path((slug, name)): Path<(String, String)>,
    Query(query): Query<HashMap<String, String>>,
) -> Result<String, StatusCode> {
    if !valid_slug(&slug) || !valid_log_name(&name) {
        return Err(StatusCode::NOT_FOUND);
    }

    // Resolving both paths refuses symlinks that point out of the server's own folder.
    let server_dir = FsPath::new(&*state.data_dir).join(&slug).canonicalize().map_err(|_| StatusCode::NOT_FOUND)?;
    let path = logs_dir(&state, &slug).join(&name).canonicalize().map_err(|_| StatusCode::NOT_FOUND)?;
    if !path.starts_with(&server_dir) || !path.is_file() {
        return Err(StatusCode::NOT_FOUND);
    }

    let file = File::open(&path).map_err(|_| StatusCode::NOT_FOUND)?;
    let mut bytes = Vec::new();
    let read = if name.ends_with(".gz") {
        GzDecoder::new(file).take(MAX_LOG_BYTES).read_to_end(&mut bytes)
    } else {
        file.take(MAX_LOG_BYTES).read_to_end(&mut bytes)
    };
    read.map_err(|_| StatusCode::UNPROCESSABLE_ENTITY)?;

    let text = String::from_utf8_lossy(&bytes);
    Ok(match query.get("q").map(|q| q.to_lowercase()).filter(|q| !q.is_empty()) {
        Some(needle) => text.lines().filter(|line| line.to_lowercase().contains(&needle)).fold(String::new(), |mut found, line| {
            found.push_str(line);
            found.push('\n');
            found
        }),
        None => text.into_owned(),
    })
}

fn mac(token: &[u8], timestamp: &str, method: &str, path: &str, body: &[u8]) -> Hmac<Sha256> {
    let mut mac = Hmac::<Sha256>::new_from_slice(token).expect("hmac accepts any key length");
    mac.update(format!("{timestamp}\n{method}\n{path}\n").as_bytes());
    mac.update(body);
    mac
}

// ponytail: no nonce, so a captured request can be replayed within MAX_SKEW_SECS; add one if the link leaves the host.
fn verify(token: &[u8], timestamp: &str, method: &str, path: &str, body: &[u8], signature: &str, now: u64) -> bool {
    let Ok(sent_at) = timestamp.parse::<u64>() else {
        return false;
    };
    let Ok(signature) = hex::decode(signature) else {
        return false;
    };
    now.abs_diff(sent_at) <= MAX_SKEW_SECS
        && mac(token, timestamp, method, path, body).verify_slice(&signature).is_ok()
}

async fn require_signature(State(state): State<AppState>, request: Request, next: Next) -> Result<Response, StatusCode> {
    let (parts, body) = request.into_parts();
    let body = to_bytes(body, MAX_BODY_BYTES).await.map_err(|_| StatusCode::PAYLOAD_TOO_LARGE)?;
    let header = |name: &str| parts.headers.get(name).and_then(|value| value.to_str().ok()).unwrap_or_default();
    let path = parts.uri.path_and_query().map_or("/", |path| path.as_str());

    if !verify(&state.token, header("x-timestamp"), parts.method.as_str(), path, &body, header("x-signature"), unix_now()) {
        return Err(StatusCode::UNAUTHORIZED);
    }

    Ok(next.run(Request::from_parts(parts, Body::from(body))).await)
}

#[tokio::main]
async fn main() {
    let token = std::env::var("MCPANEL_TOKEN").unwrap_or_default();
    assert!(token.len() >= 32, "MCPANEL_TOKEN must be set to the node token (32+ characters)");
    let listen = std::env::var("MCPANEL_LISTEN").unwrap_or_else(|_| "0.0.0.0:8080".into());
    // Docker resolves bind mounts on the host, so this path must be the same folder inside and outside.
    let data_dir = std::env::var("MCPANEL_DATA_DIR").unwrap_or_else(|_| "/srv/mc".into());
    assert!(FsPath::new(&data_dir).is_dir(), "MCPANEL_DATA_DIR ({data_dir}) is not mounted");

    let state = AppState {
        docker: Docker::connect_with_local_defaults().expect("docker socket"),
        token: token.into_bytes().into(),
        data_dir: data_dir.into(),
        tasks: Tasks::default(),
    };
    let app = Router::new()
        .route("/servers", get(list_servers))
        .route("/servers/{slug}", put(put_server).delete(delete_server))
        .route("/servers/{slug}/{action}", post(power))
        .route("/servers/{slug}/logs", get(list_logs))
        .route("/servers/{slug}/logs/{name}", get(read_log))
        .route_layer(middleware::from_fn_with_state(state.clone(), require_signature))
        // Authenticated by its console token instead of a signed request.
        .route("/servers/{slug}/console", get(console))
        .with_state(state);

    let listener = tokio::net::TcpListener::bind(&listen).await.expect("bind listen address");
    println!("listening on {listen}");
    axum::serve(listener, app).await.expect("server");
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn verify_accepts_only_a_fresh_untampered_signature() {
        let token = b"0123456789abcdef0123456789abcdef";
        let signature = hex::encode(mac(token, "1000", "POST", "/servers?x=1", b"{}").finalize().into_bytes());

        // Same vector as tests/Feature/NodeClientTest.php, so both sides agree on the format.
        assert_eq!(signature, "57e5b9892cd325a986ffcf28a917d5ba90799aa4e9ca4c683bc6b8e7cc6e7fbf");
        assert!(verify(token, "1000", "POST", "/servers?x=1", b"{}", &signature, 1000 + MAX_SKEW_SECS));
        assert!(!verify(token, "1000", "POST", "/servers?x=1", b"{}", &signature, 1001 + MAX_SKEW_SECS));
        assert!(!verify(token, "1000", "POST", "/servers?x=2", b"{}", &signature, 1000));
        assert!(!verify(token, "1000", "POST", "/servers?x=1", b"{ }", &signature, 1000));
        assert!(!verify(b"another-token-another-token-0000", "1000", "POST", "/servers?x=1", b"{}", &signature, 1000));
        assert!(!verify(token, "soon", "POST", "/servers?x=1", b"{}", "not-hex", 1000));
    }

    #[test]
    fn console_tokens_are_bound_to_one_server_and_expire() {
        let key = b"0123456789abcdef0123456789abcdef";
        let token = |key: &[u8], claims: &str| {
            let payload = hex::encode(claims);
            let mut mac = Hmac::<Sha256>::new_from_slice(key).unwrap();
            mac.update(b"console\n");
            mac.update(payload.as_bytes());
            format!("{payload}.{}", hex::encode(mac.finalize().into_bytes()))
        };
        let valid = token(key, r#"{"slug":"survival","exp":2000,"write":true}"#);

        // Same vector as tests/Feature/ConsoleTest.php.
        assert_eq!(valid.split_once('.').unwrap().1, "b909a93d1a9fdbd6306efa948ceecf684803eabbe576da1c82220feb435d7250");
        assert!(console_claims(key, &valid, "survival", 2000).is_some_and(|claims| claims.write));
        assert!(console_claims(key, &valid, "survival", 2001).is_none());
        assert!(console_claims(key, &valid, "creative", 1000).is_none());
        assert!(console_claims(b"another-token-another-token-0000", &valid, "survival", 1000).is_none());
        assert!(console_claims(key, &valid.replace("7b", "7c"), "survival", 1000).is_none());
        assert!(console_claims(key, "nonsense", "survival", 1000).is_none());

        assert!(valid_command("say hello"));
        assert!(!valid_command("--password x"));
        assert!(!valid_command("say a\nstop"));
        assert!(valid_log_name("latest.log") && valid_log_name("2026-11-14-1.log.gz"));
        assert!(!valid_log_name("../server.properties") && !valid_log_name("ops.json") && !valid_log_name(".log"));
    }

    fn config(change: impl FnOnce(&mut Value)) -> Result<ServerConfig, serde_json::Error> {
        let mut value = json!({
            "hostname": "survival.mc.example.com", "java_version": 21, "server_type": "PAPER", "mc_version": "1.21.10",
            "memory_mb": 4096, "eula": true, "motd": "Hello", "difficulty": "normal", "mode": "survival",
            "max_players": 20,
        });
        change(&mut value);
        serde_json::from_value(value)
    }

    #[test]
    fn only_known_settings_reach_the_container() {
        let valid = config(|_| {}).unwrap();
        assert_eq!(valid.validate(), Ok(()));
        assert_eq!(valid.env()[1..4], ["TYPE=PAPER", "VERSION=1.21.10", "MEMORY=3072M"]);

        assert!(config(|value| value["image"] = json!("evil/image")).is_err());
        for (field, bad) in [
            ("hostname", json!("a b")),
            ("java_version", json!(9)),
            ("server_type", json!("CUSTOM")),
            ("mc_version", json!("1.21 -e X=1")),
            ("memory_mb", json!(64)),
            ("eula", json!(false)),
            ("motd", json!("line\nbreak")),
            ("difficulty", json!("brutal")),
            ("mode", json!("god")),
            ("max_players", json!(0)),
        ] {
            assert_eq!(config(|value| value[field] = bad).unwrap().validate(), Err(field));
        }

        assert!(valid_slug("survival-2"));
        for bad in ["", "../etc", "a/b", "Upper", "-lead", "trail-", "a b"] {
            assert!(!valid_slug(bad), "{bad}");
        }
    }

    #[test]
    fn container_state_maps_to_a_panel_status() {
        let state = |status, exit_code, oom_killed, health: Option<HealthStatusEnum>| ContainerState {
            status: Some(status),
            exit_code: Some(exit_code),
            oom_killed: Some(oom_killed),
            health: health.map(|status| bollard::models::Health { status: Some(status), ..Default::default() }),
            ..Default::default()
        };
        use ContainerStateStatusEnum::{CREATED, EXITED, RUNNING};

        assert_eq!(describe(&state(RUNNING, 0, false, Some(HealthStatusEnum::HEALTHY))).0, "running");
        assert_eq!(describe(&state(RUNNING, 0, false, Some(HealthStatusEnum::STARTING))).0, "starting");
        assert_eq!(describe(&state(RUNNING, 0, false, None)).0, "running");
        assert_eq!(describe(&state(CREATED, 0, false, None)).0, "offline");
        assert_eq!(describe(&state(EXITED, 143, false, None)).0, "offline");
        assert_eq!(describe(&state(EXITED, 1, false, None)).0, "error");
        assert_eq!(describe(&state(EXITED, 137, true, None)).0, "error");
    }
}
