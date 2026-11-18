mod backups;
mod files;

use std::{
    collections::{HashMap, VecDeque},
    fs::File,
    future::Future,
    io::Read,
    net::SocketAddr,
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
    routing::{get, options, post, put},
    Json, Router,
};
use axum_server::tls_rustls::RustlsConfig;
use bollard::{
    errors::Error as DockerError,
    exec::{CreateExecOptions, StartExecResults},
    models::{
        ContainerCreateBody, ContainerInspectResponse, ContainerState, ContainerStateStatusEnum, ContainerStatsResponse,
        HealthStatusEnum, HostConfig,
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
// Holds one empty file per server that was stopped on purpose. Not a valid server name, so it can
// never collide with a server's folder or be reached through the file manager.
const STOPPED_DIR: &str = ".mcpanel-stopped";
// BlueMap's built-in web server listens here unless its config says otherwise.
const BLUEMAP_PORT: &str = "8100";
const JAVA_VERSIONS: [u8; 4] = [8, 17, 21, 25];
// Long enough for the server to save the world on SIGTERM.
pub const STOP_TIMEOUT_SECS: i32 = 60;
const MAX_SKEW_SECS: u64 = 60;
const CONSOLE_BACKLOG_LINES: &str = "200";
// How long a player count is reused before the server is asked again.
const PLAYERS_MAX_AGE_SECS: u64 = 10;
// One usage sample every ten seconds, kept for ten minutes.
const USAGE_INTERVAL_SECS: u64 = 10;
const USAGE_SAMPLES: usize = 60;
// Walking a world folder costs more than a sample, so its size is measured once a minute.
const DISK_EVERY_SAMPLES: u64 = 6;
const MAX_COMMAND_CHARS: usize = 500;
// The image runs the server as this user, and only it may write to the console pipe.
const CONSOLE_USER: &str = "1000";
// Also bounds what a gzipped log may expand to.
const MAX_LOG_BYTES: u64 = 20 * 1024 * 1024;
// ponytail: bodies are buffered to verify the signature; file uploads will need a streamed scheme.
const MAX_BODY_BYTES: usize = 1024 * 1024;

pub enum Task {
    Busy(&'static str),
    Failed(String),
}

// ponytail: running and failed tasks live in memory, so a service restart forgets them; persist if that hurts.
pub type Tasks = Arc<Mutex<HashMap<String, Task>>>;

#[derive(Clone)]
pub struct AppState {
    pub docker: Docker,
    pub token: Arc<[u8]>,
    pub data_dir: Arc<str>,
    pub backup_dir: Arc<str>,
    pub import_dir: Arc<str>,
    pub tasks: Tasks,
    // Player counts by server, with the time they were read.
    players: Arc<Mutex<HashMap<String, (u64, Value)>>>,
    // CPU and memory of each running server over the last ten minutes, oldest first.
    usage: Arc<Mutex<HashMap<String, VecDeque<Value>>>>,
    // Bytes on disk of each server's folder, measured now and then.
    disk: Arc<Mutex<HashMap<String, u64>>>,
    // One entry per backup that is running, to stop it with.
    pub cancels: Arc<Mutex<HashMap<String, Arc<tokio::sync::Notify>>>>,
    // Per server: the start it belongs to and the moment that start finished booting. Jars newer
    // than that moment were added by a person, not installed by the image, and are not loaded yet.
    booted: Arc<Mutex<HashMap<String, (String, u64)>>>,
    // The latest average milliseconds per game tick of each server that reports it.
    mspt: Arc<Mutex<HashMap<String, f64>>>,
}

#[cfg(test)]
impl AppState {
    pub fn for_tests(data_dir: &str) -> Self {
        Self {
            // Never contacted: these tests only touch the filesystem.
            docker: Docker::connect_with_http("http://127.0.0.1:1", 1, bollard::API_DEFAULT_VERSION).expect("docker client"),
            token: Arc::from(&b"0123456789abcdef0123456789abcdef"[..]),
            data_dir: data_dir.into(),
            backup_dir: "/nonexistent".into(),
            import_dir: "/nonexistent".into(),
            tasks: Tasks::default(),
            players: Default::default(),
            usage: Default::default(),
            disk: Default::default(),
            cancels: Default::default(),
            booted: Default::default(),
            mspt: Default::default(),
        }
    }
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
    // The Modrinth or CurseForge pack to install, for the two modpack server types.
    #[serde(default)]
    modpack: Option<String>,
    #[serde(default)]
    cf_api_key: Option<String>,
    // A specific FTB pack version; none installs the newest.
    #[serde(default)]
    modpack_version: Option<String>,
    // A picture for the Minecraft image to fetch as server-icon.png while the server has none.
    #[serde(default)]
    icon: Option<String>,
    // Publishes the server's BlueMap web map under /<slug>/ on the map port.
    #[serde(default)]
    bluemap: bool,
    // Makes the router send players here when they use a hostname no server has.
    #[serde(default)]
    is_default: bool,
    // Player names. The lists replace what is on disk, so the panel's form is the truth.
    #[serde(default)]
    ops: Vec<String>,
    #[serde(default)]
    whitelist: Vec<String>,
    #[serde(default)]
    whitelist_on: bool,
    #[serde(default)]
    jvm_opts: String,
    // In cores; none means no limit.
    #[serde(default)]
    cpu_limit: Option<f64>,
    // Used when the world is first generated.
    #[serde(default)]
    seed: Option<String>,
    // A world in the import folder to begin with; only looked at when the server is first created.
    #[serde(default)]
    import_host: Option<String>,
    #[serde(default)]
    start: bool,
}

fn valid_player_names(names: &[String]) -> bool {
    names.len() <= 200
        && names.iter().all(|name| {
            (1..=16).contains(&name.len()) && name.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'_')
        })
}

// Where Modrinth, CurseForge and FTB serve pack pictures; the panel has the same list (Server::PICTURE_PREFIXES).
const PICTURE_PREFIXES: [&str; 3] = ["https://cdn.modrinth.com/", "https://media.forgecdn.net/", "https://cdn.feed-the-beast.com/"];

// PUMPKIN is a server written in Rust; the image runs it as a native program and ignores the version and Java.
const LOADER_TYPES: [&str; 6] = ["VANILLA", "PAPER", "FABRIC", "FORGE", "NEOFORGE", "PUMPKIN"];

fn valid_modpack(server_type: &str, modpack: Option<&str>) -> bool {
    let plain = |text: &str, extra: &[u8]| {
        (1..=200).contains(&text.len()) && text.bytes().all(|b| b.is_ascii_alphanumeric() || extra.contains(&b))
    };

    match (server_type, modpack) {
        (loader, None) => LOADER_TYPES.contains(&loader),
        // A project slug or id, or a link to a project or version page.
        ("MODRINTH", Some(pack)) => plain(pack.strip_prefix("https://modrinth.com/").unwrap_or(pack), b"._-/"),
        ("AUTO_CURSEFORGE", Some(pack)) => plain(pack, b"-"),
        // FTB packs go by number.
        ("FTBA", Some(pack)) => pack.len() <= 9 && plain(pack, b"") && pack.bytes().all(|b| b.is_ascii_digit()),
        _ => false,
    }
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
        } else if !valid_modpack(&self.server_type, self.modpack.as_deref()) {
            Err("server_type")
        } else if self.cf_api_key.as_deref().is_some_and(|key| key.len() > 200 || !key.bytes().all(|b| b.is_ascii_graphic())) {
            Err("cf_api_key")
        } else if self.server_type == "AUTO_CURSEFORGE" && self.cf_api_key.is_none() {
            Err("cf_api_key")
        } else if self.modpack_version.as_deref().is_some_and(|version| version.is_empty() || version.len() > 9 || !version.bytes().all(|b| b.is_ascii_digit())) {
            Err("modpack_version")
        } else if !version_ok {
            Err("mc_version")
        } else if !(512..=65536).contains(&self.memory_mb) {
            Err("memory_mb")
        } else if !self.eula {
            Err("eula")
        } else if self.motd.chars().count() > 256 || self.motd.chars().any(char::is_control) {
            Err("motd")
        } else if !["peaceful", "easy", "normal", "hard"].contains(&self.difficulty.as_str()) {
            Err("difficulty")
        } else if !["survival", "creative", "adventure", "spectator"].contains(&self.mode.as_str()) {
            Err("mode")
        } else if !(1..=1000).contains(&self.max_players) {
            Err("max_players")
        } else if !valid_player_names(&self.ops) {
            Err("ops")
        } else if !valid_player_names(&self.whitelist) {
            Err("whitelist")
        } else if self.jvm_opts.len() > 500 || self.jvm_opts.chars().any(char::is_control) {
            Err("jvm_opts")
        } else if self.cpu_limit.is_some_and(|cores| !(0.25..=64.0).contains(&cores)) {
            Err("cpu_limit")
        } else if self.seed.as_deref().is_some_and(|seed| seed.chars().count() > 64 || seed.chars().any(char::is_control)) {
            Err("seed")
        } else if self.icon.as_deref().is_some_and(|icon| !PICTURE_PREFIXES.iter().any(|prefix| icon.starts_with(prefix)) || icon.len() > 300 || !icon.bytes().all(|b| b.is_ascii_graphic())) {
            // The Minecraft image downloads from this address, so only the catalogues' picture hosts.
            Err("icon")
        } else {
            Ok(())
        }
    }

    fn env(&self) -> Vec<String> {
        let mut env = self.base_env();

        match (self.server_type.as_str(), &self.modpack) {
            ("MODRINTH", Some(pack)) => env.push(format!("MODRINTH_MODPACK={pack}")),
            ("AUTO_CURSEFORGE", Some(pack)) => {
                env.push(format!("CF_SLUG={pack}"));
                env.push(format!("CF_API_KEY={}", self.cf_api_key.as_deref().unwrap_or_default()));
            }
            ("FTBA", Some(pack)) => {
                env.push(format!("FTB_MODPACK_ID={pack}"));
                if let Some(version) = &self.modpack_version {
                    env.push(format!("FTB_MODPACK_VERSION_ID={version}"));
                }
            }
            _ => {}
        }

        // SYNCHRONIZE makes the image rewrite ops.json and whitelist.json from these lists on every start.
        env.push(format!("OPS={}", self.ops.join(",")));
        env.push("EXISTING_OPS_FILE=SYNCHRONIZE".into());
        env.push(format!("WHITELIST={}", self.whitelist.join(",")));
        env.push("EXISTING_WHITELIST_FILE=SYNCHRONIZE".into());
        env.push(format!("ENABLE_WHITELIST={}", self.whitelist_on));
        env.push(format!("ENFORCE_WHITELIST={}", self.whitelist_on));
        if !self.jvm_opts.is_empty() {
            env.push(format!("JVM_OPTS={}", self.jvm_opts));
        }
        if let Some(seed) = self.seed.as_deref().filter(|seed| !seed.is_empty()) {
            env.push(format!("SEED={seed}"));
        }
        if let Some(icon) = &self.icon {
            env.push(format!("ICON={icon}"));
        }
        // The image fetches the BlueMap build that fits this server from Modrinth. The question mark
        // makes it optional: without a fitting build the server still starts, just without a map.
        if self.bluemap && self.takes_mods() {
            // BlueMap's Fabric build needs Fabric API, which only a modpack brings along itself.
            let api = if self.server_type == "FABRIC" { ",fabric-api?" } else { "" };
            env.push(format!("MODRINTH_PROJECTS=bluemap?{api}"));
        }

        env
    }

    // Pumpkin is a native program, so it runs from the image's default tag instead of one named after a Java version.
    fn image(&self) -> String {
        if self.server_type == "PUMPKIN" {
            format!("{IMAGE}:latest")
        } else {
            format!("{IMAGE}:java{}", self.java_version)
        }
    }

    // Vanilla loads no mods or plugins, and Pumpkin none made for other servers.
    fn takes_mods(&self) -> bool {
        !matches!(self.server_type.as_str(), "VANILLA" | "PUMPKIN")
    }

    fn base_env(&self) -> Vec<String> {
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
            // Lets commands be written to the server's own console instead of going through RCON.
            "CREATE_CONSOLE_IN_PIPE=true".into(),
        ]
    }
}

// A slug names the container and the data folder, so it must never carry a path or option.
pub fn valid_slug(slug: &str) -> bool {
    (1..=63).contains(&slug.len())
        && slug.bytes().all(|b| b.is_ascii_lowercase() || b.is_ascii_digit() || b == b'-')
        && !slug.starts_with('-')
        && !slug.ends_with('-')
}

pub fn container_name(slug: &str) -> String {
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
pub async fn managed(docker: &Docker, slug: &str) -> Result<Option<ContainerInspectResponse>, StatusCode> {
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

// Records whether a server was stopped on purpose, so a reboot brings back only the others.
pub fn set_stopped(state: &AppState, slug: &str, stopped: bool) {
    let dir = FsPath::new(&*state.data_dir).join(STOPPED_DIR);
    let result = if stopped {
        std::fs::create_dir_all(&dir).and_then(|()| std::fs::write(dir.join(slug), ""))
    } else {
        match std::fs::remove_file(dir.join(slug)) {
            Err(error) if error.kind() != std::io::ErrorKind::NotFound => Err(error),
            _ => Ok(()),
        }
    };

    if let Err(error) = result {
        eprintln!("could not record the stopped state of {slug}: {error}");
    }
}

// Runs once when the service starts, which is also what happens after the host reboots:
// every server that is not running and was not stopped on purpose is started again.
async fn start_after_boot(state: AppState) {
    let label = format!("{MANAGED_LABEL}=true");
    let filters = HashMap::from([("label", vec![label.as_str()])]);
    let options = ListContainersOptionsBuilder::default().all(true).filters(&filters).build();
    let Ok(containers) = state.docker.list_containers(Some(options)).await else {
        eprintln!("could not list servers to start after boot");
        return;
    };

    for container in containers {
        let names = container.names.unwrap_or_default();
        let Some(slug) = names.first().and_then(|name| name.strip_prefix("/mc-")) else {
            continue;
        };
        let running = container.state == Some(bollard::models::ContainerSummaryStateEnum::RUNNING);
        let stopped_on_purpose = FsPath::new(&*state.data_dir).join(STOPPED_DIR).join(slug).is_file();

        if !running && !stopped_on_purpose {
            match state.docker.start_container(&container_name(slug), None::<StartContainerOptions>).await {
                Ok(()) => println!("started {slug} after boot"),
                Err(error) => eprintln!("could not start {slug} after boot: {error}"),
            }
        }
    }
}

// The host's memory, so the panel can refuse to hand out more than there is.
async fn host(State(state): State<AppState>) -> Result<Json<Value>, StatusCode> {
    let info = state.docker.info().await.map_err(|error| {
        eprintln!("docker info failed: {error}");
        StatusCode::BAD_GATEWAY
    })?;

    Ok(Json(json!({ "memory_mb": info.mem_total.unwrap_or(0) / 1024 / 1024 })))
}

// Runs slow Docker work in the background so the panel's request returns at once.
pub fn run_task<F, E>(tasks: &Tasks, slug: String, label: &'static str, work: F) -> StatusCode
where
    F: Future<Output = Result<(), E>> + Send + 'static,
    E: std::fmt::Display,
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

type TaskError = Box<dyn std::error::Error + Send + Sync>;

async fn apply(
    state: AppState,
    slug: String,
    config: ServerConfig,
    existing: Option<bool>,
    import: Option<PathBuf>,
) -> Result<(), TaskError> {
    let (docker, data_dir) = (state.docker.clone(), state.data_dir.clone());
    if let (None, Some(source)) = (existing, &import) {
        backups::seed_world(&state, &slug, source).await?;
    }
    backups::ensure_folder(&state, &slug).await?;
    if config.bluemap && config.takes_mods() {
        backups::accept_bluemap_download(&state, &slug, config.server_type == "PAPER").await?;
    }

    let image = config.image();
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
        labels: Some(labels(&slug, &config)),
        host_config: Some(HostConfig {
            binds: Some(vec![format!("{data_dir}/{slug}:/data")]),
            memory: Some(i64::from(config.memory_mb) * 1024 * 1024),
            nano_cpus: config.cpu_limit.map(|cores| (cores * 1e9) as i64),
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

fn labels(slug: &str, config: &ServerConfig) -> HashMap<String, String> {
    let mut labels = HashMap::from([
        (MANAGED_LABEL.to_owned(), "true".to_owned()),
        ("mc-router.host".to_owned(), config.hostname.clone()),
    ]);

    if config.is_default {
        labels.insert("mc-router.default".to_owned(), "true".to_owned());
    }

    if config.bluemap {
        let router = format!("traefik.http.routers.bluemap-{slug}");
        let strip = format!("bluemap-{slug}-strip");
        labels.extend([
            ("traefik.enable".to_owned(), "true".to_owned()),
            ("traefik.docker.network".to_owned(), NETWORK.to_owned()),
            (format!("{router}.rule"), format!("PathPrefix(`/{slug}/`)")),
            (format!("{router}.entrypoints"), "bluemap".to_owned()),
            (format!("{router}.middlewares"), strip.clone()),
            (format!("traefik.http.middlewares.{strip}.stripprefix.prefixes"), format!("/{slug}")),
            (format!("traefik.http.services.bluemap-{slug}.loadbalancer.server.port"), BLUEMAP_PORT.to_owned()),
        ]);
    }

    labels
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
    let import = match config.import_host.as_deref().filter(|path| !path.is_empty()) {
        Some(path) => Some(backups::host_world(&state, path).map_err(|status| (status, "import_host".into()))?),
        None => None,
    };
    if config.start {
        set_stopped(&state, &slug, false);
    }
    let work = apply(state.clone(), slug.clone(), config, existing, import);

    Ok(run_task(&state.tasks, slug, "installing", work))
}

async fn power(State(state): State<AppState>, Path((slug, action)): Path<(String, String)>) -> Result<StatusCode, StatusCode> {
    if !valid_slug(&slug) || managed(&state.docker, &slug).await?.is_none() {
        return Err(StatusCode::NOT_FOUND);
    }

    match action.as_str() {
        "start" | "restart" => set_stopped(&state, &slug, false),
        "stop" | "kill" => set_stopped(&state, &slug, true),
        _ => {}
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
    set_stopped(&state, &slug, false);
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
    let mut player_counts: HashMap<String, Value> = HashMap::new();
    // Mod and plugin jars put in place after the server last started, so not loaded yet.
    let mut changed_mods: HashMap<String, usize> = HashMap::new();
    for container in containers {
        let names = container.names.unwrap_or_default();
        let Some(slug) = names.first().and_then(|name| name.strip_prefix("/mc-")) else {
            continue;
        };
        // One inspect per server: the list call has no exit code or health.
        if let Ok(Some(inspected)) = managed(&state.docker, slug).await {
            let (label, detail) = describe(inspected.state.as_ref().unwrap_or(&ContainerState::default()));
            if label == "running" {
                player_counts.insert(slug.to_owned(), players(&state, slug).await);
                let started_at = inspected.state.as_ref().and_then(|state| state.started_at.clone()).unwrap_or_default();
                let booted = state.booted.lock().unwrap().get(slug).filter(|(start, _)| *start == started_at).map(|(_, at)| *at);
                changed_mods.insert(slug.to_owned(), booted.map_or(0, |booted| mods_newer_than(&state, slug, booted)));
            }
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

    let usage = state.usage.lock().unwrap().clone();
    let disk = state.disk.lock().unwrap().clone();
    let last_backups: HashMap<String, u64> =
        servers.keys().filter_map(|slug| Some((slug.clone(), backups::latest(&state, slug)?))).collect();

    Ok(Json(Value::Array(
        servers
            .into_iter()
            .map(|(slug, (state, detail))| {
                // A busy server (backing up, restarting) reports no players, even if it had some a moment ago.
                let players = if state == "running" { player_counts.remove(&slug) } else { None };
                let usage = if state == "running" { usage.get(&slug).and_then(|samples| samples.back().cloned()) } else { None };
                json!({
                    "slug": slug, "state": state, "detail": detail, "players": players, "usage": usage,
                    "disk_bytes": disk.get(&slug),
                    "last_backup": last_backups.get(&slug),
                    "mods_changed": changed_mods.get(&slug).copied().unwrap_or(0),
                })
            })
            .collect(),
    )))
}

pub fn unix_now() -> u64 {
    SystemTime::now().duration_since(UNIX_EPOCH).map_or(0, |elapsed| elapsed.as_secs())
}

#[derive(Deserialize)]
pub struct Claims {
    slug: String,
    exp: u64,
    pub write: bool,
}

// A browser token is hex(json claims) + "." + hex(hmac of purpose + "\n" + hex claims), minted by the panel.
// The purpose ("console" or "files") keeps a token for one from working on the other.
pub fn claims(key: &[u8], purpose: &str, token: &str, slug: &str, now: u64) -> Option<Claims> {
    let (payload, signature) = token.split_once('.')?;
    let mut mac = Hmac::<Sha256>::new_from_slice(key).ok()?;
    mac.update(purpose.as_bytes());
    mac.update(b"\n");
    mac.update(payload.as_bytes());
    mac.verify_slice(&hex::decode(signature).ok()?).ok()?;

    let claims: Claims = serde_json::from_slice(&hex::decode(payload).ok()?).ok()?;
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

pub async fn run_command(docker: &Docker, name: &str, command: &str) -> String {
    let command = command.trim().trim_start_matches('/');
    if !valid_command(command) {
        return "That command cannot be sent.\n".into();
    }

    // Written to the server's console as if typed there, so the answer shows up in the log like any
    // other output. A container made before the pipe existed falls back to RCON, which answers directly.
    if let Ok((_, 0)) = exec(docker, name, vec!["mc-send-to-console", command], Some(CONSOLE_USER)).await {
        return String::new();
    }

    exec(docker, name, vec!["rcon-cli", command], None)
        .await
        .map(|(reply, _)| reply)
        .unwrap_or_else(|_| "The command could not be run. Is the server running?\n".into())
}

// Runs a program inside the server's container and returns what it printed and its exit code.
async fn exec(docker: &Docker, name: &str, command: Vec<&str>, user: Option<&str>) -> Result<(String, i64), DockerError> {
    let options = CreateExecOptions {
        cmd: Some(command),
        user,
        attach_stdout: Some(true),
        attach_stderr: Some(true),
        ..Default::default()
    };
    let created = docker.create_exec(name, options).await?;
    let mut text = String::new();

    if let StartExecResults::Attached { mut output, .. } = docker.start_exec(&created.id, None).await? {
        while let Some(Ok(chunk)) = output.next().await {
            text.push_str(&chunk.to_string());
        }
    }

    Ok((text, docker.inspect_exec(&created.id).await?.exit_code.unwrap_or(0)))
}

// "tick query" answers "Average time per tick: 4.2ms (Target: 50.0ms)" on Minecraft 1.20.3 and later.
fn parse_mspt(reply: &str) -> Option<f64> {
    reply.split("Average time per tick: ").nth(1)?.split("ms").next()?.trim().parse().ok()
}

// Who is online, from the same status ping a Minecraft client's server list uses. Unlike the
// "list" command it leaves nothing in the server log. Null while the server is not answering.
async fn players(state: &AppState, slug: &str) -> Value {
    let now = unix_now();
    if let Some((read_at, cached)) = state.players.lock().unwrap().get(slug) {
        if now.saturating_sub(*read_at) < PLAYERS_MAX_AGE_SECS {
            return cached.clone();
        }
    }

    let status = exec(&state.docker, &container_name(slug), vec!["mc-monitor", "status", "-json", "-timeout", "3s"], None).await;
    let info = status.ok().and_then(|(text, _)| serde_json::from_str::<Value>(&text).ok());
    let value = match info.as_ref().map(|info| &info["server_info"]["players"]) {
        Some(found) if found["max"].is_u64() => json!({
            "online": found["online"],
            "max": found["max"],
            // The server sends at most a dozen names.
            "names": found["Sample"].as_array().map(|sample| sample.iter().map(|player| player["name"].clone()).collect::<Vec<_>>()).unwrap_or_default(),
        }),
        _ => Value::Null,
    };

    state.players.lock().unwrap().insert(slug.to_owned(), (now, value.clone()));
    value
}

fn mods_newer_than(state: &AppState, slug: &str, since: u64) -> usize {
    let server = FsPath::new(&*state.data_dir).join(slug);
    let newer = |metadata: std::fs::Metadata| metadata.modified().ok()?.duration_since(UNIX_EPOCH).ok().filter(|modified| modified.as_secs() > since);
    let jars = ["mods", "plugins"]
        .iter()
        .flat_map(|folder| std::fs::read_dir(server.join(folder)).into_iter().flatten().flatten())
        .filter(|entry| entry.file_name().to_string_lossy().ends_with(".jar"))
        .filter_map(|entry| newer(entry.metadata().ok()?))
        .count();

    // A removed mod leaves no newer jar behind, only a folder that changed.
    if jars == 0 && server.join("mods").metadata().ok().and_then(newer).is_some() {
        1
    } else {
        jars
    }
}

async fn folder_bytes(path: PathBuf) -> Option<u64> {
    let output = tokio::process::Command::new("du").arg("-sb").arg(&path).output().await.ok()?;

    String::from_utf8_lossy(&output.stdout).split_whitespace().next()?.parse().ok()
}

// CPU is the share of one core used since the previous sample, so the first sample reads 0.
fn usage_sample(previous_cpu: &mut Option<(u64, u64)>, sample: ContainerStatsResponse) -> Value {
    let cpu = sample.cpu_stats.unwrap_or_default();
    let now = (cpu.cpu_usage.and_then(|usage| usage.total_usage).unwrap_or(0), cpu.system_cpu_usage.unwrap_or(0));
    let percent = match previous_cpu.replace(now) {
        Some((total, system)) if now.1 > system => {
            now.0.saturating_sub(total) as f64 / (now.1 - system) as f64 * f64::from(cpu.online_cpus.unwrap_or(1)) * 100.0
        }
        _ => 0.0,
    };
    let memory = sample.memory_stats.unwrap_or_default();
    // Like "docker stats": file cache the kernel can drop is not counted as used.
    let cache = memory.stats.as_ref().and_then(|stats| stats.get("inactive_file")).copied().unwrap_or(0);

    json!({
        "at": unix_now(),
        "cpu": (percent * 10.0).round() / 10.0,
        "memory": memory.usage.unwrap_or(0).saturating_sub(cache),
        "memory_limit": memory.limit.unwrap_or(0),
    })
}

// Samples every running server in the background, so the Servers page and a console that has just
// been opened can show the last ten minutes without anyone having had a page open.
async fn monitor(state: AppState) {
    let mut previous_cpu: HashMap<String, Option<(u64, u64)>> = HashMap::new();
    let mut tick = tokio::time::interval(Duration::from_secs(USAGE_INTERVAL_SECS));
    let label = format!("{MANAGED_LABEL}=true");
    let mut round = 0u64;

    loop {
        tick.tick().await;
        let slow_round = round % DISK_EVERY_SAMPLES == 0;
        round += 1;

        let filters = HashMap::from([("label", vec![label.as_str()]), ("status", vec!["running"])]);
        let options = ListContainersOptionsBuilder::default().filters(&filters).build();
        let Ok(containers) = state.docker.list_containers(Some(options)).await else { continue };
        let running: Vec<String> = containers
            .into_iter()
            .filter_map(|container| container.names?.first()?.strip_prefix("/mc-").map(str::to_owned))
            .collect();

        for slug in &running {
            let options = StatsOptionsBuilder::default().stream(false).one_shot(true).build();
            let Some(Ok(stats)) = state.docker.stats(&container_name(slug), Some(options)).next().await else { continue };
            let previous = previous_cpu.entry(slug.clone()).or_default();
            let first = previous.is_none();
            let mut sample = usage_sample(previous, stats);

            // Only a server that answers can be asked how long its ticks take. This goes through RCON
            // because the answer is needed here, not in the log. Every RCON connection leaves two
            // lines in the server's log, so it is asked once a minute, not with every sample.
            if players(&state, slug).await.is_null() {
                state.mspt.lock().unwrap().remove(slug);
            } else if slow_round {
                let reply = exec(&state.docker, &container_name(slug), vec!["rcon-cli", "tick query"], None).await;
                match reply.ok().and_then(|(reply, _)| parse_mspt(&reply)) {
                    Some(mspt) => state.mspt.lock().unwrap().insert(slug.clone(), mspt),
                    None => state.mspt.lock().unwrap().remove(slug),
                };
            }
            sample["mspt"] = json!(state.mspt.lock().unwrap().get(slug));

            // The first sample has no CPU reading yet.
            if !first {
                let mut usage = state.usage.lock().unwrap();
                let samples = usage.entry(slug.clone()).or_default();
                samples.push_back(sample);
                while samples.len() > USAGE_SAMPLES {
                    samples.pop_front();
                }
            }

            // Once per start, when the server first answers as running: from then on a new jar is a person's doing.
            if let Ok(Some(inspected)) = managed(&state.docker, slug).await {
                let container = inspected.state.unwrap_or_default();
                let started_at = container.started_at.clone().unwrap_or_default();
                let mut booted = state.booted.lock().unwrap();
                if describe(&container).0 == "running" && booted.get(slug).is_none_or(|(start, _)| *start != started_at) {
                    booted.insert(slug.clone(), (started_at, unix_now()));
                }
            }

            // Also the first time a server is seen, so a new one has a figure at once.
            if slow_round || !state.disk.lock().unwrap().contains_key(slug) {
                if let Some(bytes) = folder_bytes(FsPath::new(&*state.data_dir).join(slug)).await {
                    state.disk.lock().unwrap().insert(slug.clone(), bytes);
                }
            }
        }

        previous_cpu.retain(|slug, _| running.contains(slug));
        state.usage.lock().unwrap().retain(|slug, _| running.contains(slug));
        state.mspt.lock().unwrap().retain(|slug, _| running.contains(slug));
    }
}

#[derive(Deserialize)]
struct CommandBody {
    command: String,
}

// For scheduled commands; the console sends its commands over the WebSocket.
async fn command(State(state): State<AppState>, Path(slug): Path<String>, Json(body): Json<CommandBody>) -> Result<String, StatusCode> {
    if !valid_slug(&slug) || managed(&state.docker, &slug).await?.is_none() {
        return Err(StatusCode::NOT_FOUND);
    }

    Ok(run_command(&state.docker, &container_name(&slug), &body.command).await)
}

async fn console_session(state: AppState, slug: String, write: bool, mut socket: WebSocket) {
    let docker = state.docker.clone();
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
    let mut player_check = tokio::time::interval(Duration::from_secs(PLAYERS_MAX_AGE_SECS));

    let history: Vec<Value> = state.usage.lock().unwrap().get(&slug).map(|samples| samples.iter().cloned().collect()).unwrap_or_default();
    if socket.send(Message::Text(json!({ "t": "history", "d": history }).to_string().into())).await.is_err() {
        return;
    }

    loop {
        let frame = tokio::select! {
            message = socket.recv() => match message {
                Some(Ok(Message::Text(command))) if write => {
                    json!({ "t": "out", "d": run_command(&docker, &name, command.as_str()).await })
                }
                Some(Ok(Message::Close(_))) | Some(Err(_)) | None => break,
                Some(Ok(_)) => continue,
            },
            Some(chunk) = logs.next() => {
                let text = chunk.to_string();
                // Left by the tick time measurement, once a minute; noise to someone reading the console.
                if text.contains("Thread RCON Client") {
                    continue;
                }
                // Someone came or went: read the player list again right away instead of at the next round.
                if text.contains("joined the game") || text.contains("left the game") {
                    state.players.lock().unwrap().remove(&slug);
                    player_check.reset_after(Duration::from_secs(1));
                }
                json!({ "t": "log", "d": text })
            }
            _ = player_check.tick() => json!({ "t": "players", "d": players(&state, &slug).await }),
            Some(sample) = stats.next() => {
                let mut frame = usage_sample(&mut previous_cpu, sample);
                frame["t"] = json!("stats");
                frame["mspt"] = json!(state.mspt.lock().unwrap().get(&slug));
                frame
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
    let Some(claims) = claims(&state.token, "console", token, &slug, unix_now()) else {
        return StatusCode::UNAUTHORIZED.into_response();
    };
    if !valid_slug(&slug) {
        return StatusCode::NOT_FOUND.into_response();
    }
    if let Err(status) = managed(&state.docker, &slug).await {
        return status.into_response();
    }

    upgrade.on_upgrade(move |socket| console_session(state, slug, claims.write, socket))
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
    let token = std::env::var("SPAWNPOINT_TOKEN").unwrap_or_default();
    assert!(token.len() >= 32, "SPAWNPOINT_TOKEN must be set to the node token (32+ characters)");
    let listen = std::env::var("SPAWNPOINT_LISTEN").unwrap_or_else(|_| "0.0.0.0:8080".into());
    // Docker resolves bind mounts on the host, so this path must be the same folder inside and outside.
    let data_dir = std::env::var("SPAWNPOINT_DATA_DIR").unwrap_or_else(|_| "/srv/mc".into());
    assert!(FsPath::new(&data_dir).is_dir(), "SPAWNPOINT_DATA_DIR ({data_dir}) is not mounted");

    let backup_dir = std::env::var("SPAWNPOINT_BACKUP_DIR").unwrap_or_else(|_| "/srv/mc-backups".into());
    assert!(FsPath::new(&backup_dir).is_dir(), "SPAWNPOINT_BACKUP_DIR ({backup_dir}) is not mounted");
    // Inside the data folder, the file manager could reach the archives.
    assert!(
        !FsPath::new(&backup_dir).starts_with(&data_dir) && !FsPath::new(&data_dir).starts_with(&backup_dir),
        "SPAWNPOINT_BACKUP_DIR and SPAWNPOINT_DATA_DIR must not contain each other"
    );
    // Optional: host worlds offered for import. Nothing is listed when it is not mounted.
    let import_dir = std::env::var("SPAWNPOINT_IMPORT_DIR").unwrap_or_else(|_| "/srv/mc-import".into());

    let state = AppState {
        docker: Docker::connect_with_local_defaults().expect("docker socket"),
        token: token.into_bytes().into(),
        data_dir: data_dir.into(),
        backup_dir: backup_dir.into(),
        import_dir: import_dir.into(),
        tasks: Tasks::default(),
        players: Default::default(),
        usage: Default::default(),
        disk: Default::default(),
        cancels: Default::default(),
        booted: Default::default(),
        mspt: Default::default(),
    };
    let app = Router::new()
        .route("/servers", get(list_servers))
        .route("/servers/{slug}", put(put_server).delete(delete_server))
        .route("/servers/{slug}/{action}", post(power))
        .route("/servers/{slug}/command", post(command))
        .route("/servers/{slug}/logs", get(list_logs))
        .route("/servers/{slug}/logs/{name}", get(read_log))
        .route("/servers/{slug}/files", get(files::list).delete(files::delete))
        .route("/servers/{slug}/files/content", get(files::read_text).put(files::write_text))
        .route("/servers/{slug}/files/mkdir", post(files::mkdir))
        .route("/servers/{slug}/files/rename", post(files::rename))
        .route("/servers/{slug}/mods", get(files::list_mods).post(files::install_mod))
        .route("/servers/{slug}/backups", get(backups::list).post(backups::create))
        .route("/servers/{slug}/backups/running", axum::routing::delete(backups::cancel))
        .route("/servers/{slug}/backups/{name}", axum::routing::delete(backups::delete))
        .route("/servers/{slug}/backups/{name}/restore", post(backups::restore_backup))
        .route("/servers/{slug}/backups/{name}/lock", post(backups::lock))
        .route("/servers/{slug}/fetch", post(backups::fetch))
        .route("/servers/{slug}/import", post(backups::import_world))
        .route("/imports", get(backups::list_imports))
        .route("/host", get(host))
        .route_layer(middleware::from_fn_with_state(state.clone(), require_signature))
        // Authenticated by its console token instead of a signed request.
        .route("/servers/{slug}/console", get(console))
        .route("/servers/{slug}/upload", put(files::upload).merge(options(files::upload_preflight)))
        .route("/servers/{slug}/download", get(files::download))
        .route("/servers/{slug}/backups/{name}/download", get(backups::download))
        .with_state(state.clone());

    tokio::spawn(start_after_boot(state.clone()));
    tokio::spawn(monitor(state));

    // Given a certificate and its key, the node speaks https, so the panel's traffic is encrypted
    // as well as signed. Without them it is plain http, which belongs on a private network.
    let tls = std::env::var("SPAWNPOINT_TLS_CERT").ok().zip(std::env::var("SPAWNPOINT_TLS_KEY").ok());

    match tls.filter(|(cert, key)| !cert.is_empty() && !key.is_empty()) {
        Some((cert, key)) => {
            let address: SocketAddr = listen.parse().expect("SPAWNPOINT_LISTEN must be an address and port, such as 0.0.0.0:8080");
            let config = RustlsConfig::from_pem_file(&cert, &key).await.expect("read SPAWNPOINT_TLS_CERT and SPAWNPOINT_TLS_KEY");
            println!("listening on {listen} with TLS");
            axum_server::bind_rustls(address, config).serve(app.into_make_service()).await.expect("server");
        }
        None => {
            let listener = tokio::net::TcpListener::bind(&listen).await.expect("bind listen address");
            println!("listening on {listen}");
            axum::serve(listener, app).await.expect("server");
        }
    }
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
        assert!(claims(key, "console", &valid, "survival", 2000).is_some_and(|claims| claims.write));
        assert!(claims(key, "files", &valid, "survival", 2000).is_none());
        assert!(claims(key, "console", &valid, "survival", 2001).is_none());
        assert!(claims(key, "console", &valid, "creative", 1000).is_none());
        assert!(claims(b"another-token-another-token-0000", "console", &valid, "survival", 1000).is_none());
        assert!(claims(key, "console", &valid.replace("7b", "7c"), "survival", 1000).is_none());
        assert!(claims(key, "console", "nonsense", "survival", 1000).is_none());

        assert!(valid_command("say hello"));
        assert!(!valid_command("--password x"));
        assert!(!valid_command("say a\nstop"));
        assert!(valid_log_name("latest.log") && valid_log_name("2026-11-14-1.log.gz"));
        assert!(!valid_log_name("../server.properties") && !valid_log_name("ops.json") && !valid_log_name(".log"));
    }

    #[test]
    fn the_tick_time_is_read_from_the_tick_query_reply() {
        let reply = "The game is running normally\nTarget tick rate: 20.0 per second.\nAverage time per tick: 4.2ms (Target: 50.0ms)\nPercentiles: P50: 3.9ms";
        assert_eq!(parse_mspt(reply), Some(4.2));
        assert_eq!(parse_mspt("Unknown or incomplete command"), None);
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
    fn a_pack_picture_is_a_plain_https_address() {
        let with = |icon: &str| config(|value| value["icon"] = json!(icon)).unwrap();
        let picture = "https://cdn.modrinth.com/data/x/icon.png";

        assert_eq!(with(picture).validate(), Ok(()));
        assert!(with(picture).env().contains(&format!("ICON={picture}")));
        assert!(!config(|_| {}).unwrap().env().iter().any(|entry| entry.starts_with("ICON=")));
        assert_eq!(with("http://cdn.modrinth.com/icon.png").validate(), Err("icon"));
        assert_eq!(with("/etc/passwd").validate(), Err("icon"));
        assert_eq!(with(&format!("{picture}{}", "a".repeat(300))).validate(), Err("icon"));
        assert_eq!(with("https://cdn.modrinth.com/a\nb.png").validate(), Err("icon"));
        assert_eq!(with("https://169.254.169.254/latest/meta-data").validate(), Err("icon"));
        assert_eq!(with("https://cdn.modrinth.com.evil.example/icon.png").validate(), Err("icon"));
        assert_eq!(with("https://cdn.modrinth.com/a b.png").validate(), Err("icon"));
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

        let players = config(|value| {
            value["ops"] = json!(["JoppeK"]);
            value["whitelist"] = json!(["JoppeK", "Lien_B"]);
            value["whitelist_on"] = json!(true);
            value["jvm_opts"] = json!("-XX:+UseZGC");
            value["cpu_limit"] = json!(2.5);
        })
        .unwrap();
        assert_eq!(players.validate(), Ok(()));
        for expected in ["OPS=JoppeK", "WHITELIST=JoppeK,Lien_B", "ENABLE_WHITELIST=true", "JVM_OPTS=-XX:+UseZGC"] {
            assert!(players.env().contains(&expected.to_owned()), "{expected}");
        }
        assert!(valid.env().contains(&"ENABLE_WHITELIST=false".to_owned()));
        assert!(!valid.env().iter().any(|entry| entry.starts_with("SEED=")));
        assert_eq!(valid.image(), "itzg/minecraft-server:java21");
        assert_eq!(config(|value| value["server_type"] = json!("PUMPKIN")).unwrap().image(), "itzg/minecraft-server:latest");
        assert!(!valid.env().iter().any(|entry| entry.starts_with("MODRINTH_PROJECTS=")));
        assert!(config(|value| value["bluemap"] = json!(true)).unwrap().env().contains(&"MODRINTH_PROJECTS=bluemap?".to_owned()));
        let fabric_with_map = config(|value| { value["bluemap"] = json!(true); value["server_type"] = json!("FABRIC"); }).unwrap();
        assert!(fabric_with_map.env().contains(&"MODRINTH_PROJECTS=bluemap?,fabric-api?".to_owned()));
        let vanilla_with_map = config(|value| { value["bluemap"] = json!(true); value["server_type"] = json!("VANILLA"); }).unwrap();
        assert!(!vanilla_with_map.env().iter().any(|entry| entry.starts_with("MODRINTH_PROJECTS=")));
        assert!(config(|value| value["seed"] = json!("-4172144997902289642")).unwrap().env().contains(&"SEED=-4172144997902289642".to_owned()));
        for (field, bad) in [
            ("ops", json!(["name with space"])),
            ("whitelist", json!(["a,b"])),
            ("jvm_opts", json!("-Xmx1G\n-evil")),
            ("cpu_limit", json!(0.01)),
            ("seed", json!("a\nb")),
        ] {
            assert_eq!(config(|value| value[field] = bad).unwrap().validate(), Err(field));
        }

        let with_map = config(|value| value["bluemap"] = json!(true)).unwrap();
        assert_eq!(labels("survival", &with_map)["traefik.http.routers.bluemap-survival.rule"], "PathPrefix(`/survival/`)");
        assert!(!labels("survival", &valid).contains_key("traefik.enable"));
        assert!(!labels("survival", &valid).contains_key("mc-router.default"));
        let default = config(|value| value["is_default"] = json!(true)).unwrap();
        assert_eq!(labels("survival", &default)["mc-router.default"], "true");

        let pack = |server_type: &str, modpack: Value| {
            config(|value| {
                value["server_type"] = json!(server_type);
                value["modpack"] = modpack;
                value["cf_api_key"] = json!("$2a$10$key");
            })
            .unwrap()
        };
        assert_eq!(pack("FABRIC", Value::Null).validate(), Ok(()));
        assert_eq!(pack("MODRINTH", json!("https://modrinth.com/modpack/cobblemon-fabric/version/1.6")).validate(), Ok(()));
        assert!(pack("MODRINTH", json!("cobblemon-fabric")).env().contains(&"MODRINTH_MODPACK=cobblemon-fabric".to_owned()));
        assert!(pack("AUTO_CURSEFORGE", json!("all-the-mods-10")).env().contains(&"CF_API_KEY=$2a$10$key".to_owned()));
        let ftb = config(|value| {
            value["server_type"] = json!("FTBA");
            value["modpack"] = json!("100");
            value["modpack_version"] = json!("100359");
        })
        .unwrap();
        assert_eq!(ftb.validate(), Ok(()));
        assert!(ftb.env().contains(&"FTB_MODPACK_ID=100".to_owned()) && ftb.env().contains(&"FTB_MODPACK_VERSION_ID=100359".to_owned()));
        assert_eq!(pack("FTBA", json!("stoneblock")).validate(), Err("server_type"));
        assert_eq!(config(|value| value["modpack_version"] = json!("1.2; x")).unwrap().validate(), Err("modpack_version"));
        assert_eq!(pack("MODRINTH", Value::Null).validate(), Err("server_type"));
        assert_eq!(pack("PAPER", json!("some-pack")).validate(), Err("server_type"));
        assert_eq!(pack("MODRINTH", json!("https://evil.example/pack.mrpack")).validate(), Err("server_type"));
        assert_eq!(pack("AUTO_CURSEFORGE", json!("pack name; rm -rf")).validate(), Err("server_type"));
        assert_eq!(config(|value| { value["server_type"] = json!("AUTO_CURSEFORGE"); value["modpack"] = json!("atm10"); }).unwrap().validate(), Err("cf_api_key"));

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

    #[test]
    fn a_removed_mod_counts_as_one_change() {
        let base = std::env::temp_dir().join(format!("spawnpoint-removed-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&base);
        std::fs::create_dir_all(base.join("survival/mods")).unwrap();
        let state = AppState::for_tests(base.to_str().unwrap());

        assert_eq!(mods_newer_than(&state, "survival", 0), 1);
        assert_eq!(mods_newer_than(&state, "survival", unix_now() + 60), 0);
        std::fs::write(base.join("survival/mods/a.jar"), "abc").unwrap();
        std::fs::write(base.join("survival/mods/b.jar"), "abc").unwrap();
        assert_eq!(mods_newer_than(&state, "survival", 0), 2);

        std::fs::remove_dir_all(&base).unwrap();
    }
}
