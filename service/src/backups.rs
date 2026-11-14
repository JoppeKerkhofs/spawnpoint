// Backups, restores and world imports. Archives live in the backup folder, outside every server folder,
// so the file manager can never reach them.

use std::{
    collections::HashMap,
    ffi::OsStr,
    os::unix::fs::MetadataExt,
    path::{Path as FsPath, PathBuf},
    process::Stdio,
    sync::Arc,
    time::UNIX_EPOCH,
};

use axum::{
    extract::{Path, Query, State},
    http::StatusCode,
    response::Response,
    Json,
};
use bollard::query_parameters::{StartContainerOptions, StopContainerOptionsBuilder};
use serde::Deserialize;
use serde_json::{json, Value};
use tokio::{io::AsyncReadExt, process::Command, sync::Notify};

use crate::{
    claims, container_name,
    files::{resolve_in, send_file},
    managed, run_command, run_task, set_stopped, unix_now, valid_slug, AppState, STOP_TIMEOUT_SECS,
};

// Left out of archives and kept through restores: the server recreates or re-downloads them.
const EXCLUDED: [&str; 5] = ["logs", "cache", "libraries", "versions", ".import"];
// A vanilla world keeps its dimensions inside "world"; Paper splits them into these folders.
const WORLD_FOLDERS: [&str; 3] = ["world", "world_nether", "world_the_end"];

fn server_dir(state: &AppState, slug: &str) -> PathBuf {
    FsPath::new(&*state.data_dir).join(slug)
}

fn backup_dir(state: &AppState, slug: &str) -> PathBuf {
    FsPath::new(&*state.backup_dir).join(slug)
}

// "<unix seconds>.tar.gz", or "<unix seconds>.locked.tar.gz" for one that retention must keep.
fn valid_backup_name(name: &str) -> bool {
    name.strip_suffix(".tar.gz")
        .map(|stem| stem.strip_suffix(".locked").unwrap_or(stem))
        .is_some_and(|stamp| (1..=12).contains(&stamp.len()) && stamp.bytes().all(|b| b.is_ascii_digit()))
}

// What a backup that was stopped on purpose reports, so it is not recorded as a failure.
const CANCELLED: &str = "cancelled";

// The time a backup was started, which begins the name of everything that belongs to it:
// the archive, "<stamp>.by" with who started it, and "<stamp>.failed" with why it failed.
fn stamp(name: &str) -> &str {
    name.trim_start_matches('.').split('.').next().unwrap_or_default()
}

fn valid_failure_name(name: &str) -> bool {
    name.strip_suffix(".failed").is_some_and(|stamp| (1..=12).contains(&stamp.len()) && stamp.bytes().all(|b| b.is_ascii_digit()))
}

// The newest backup of a server, as unix seconds.
pub fn latest(state: &AppState, slug: &str) -> Option<u64> {
    stored(&backup_dir(state, slug)).first().map(|(created, _, _)| *created)
}

fn backup_path(state: &AppState, slug: &str, name: &str) -> Result<PathBuf, StatusCode> {
    let path = backup_dir(state, slug).join(name);

    if valid_slug(slug) && valid_backup_name(name) && path.is_file() {
        Ok(path)
    } else {
        Err(StatusCode::NOT_FOUND)
    }
}

async fn run(program: &str, arguments: &[&OsStr], accepted: &[i32]) -> Result<(), String> {
    let output = Command::new(program).args(arguments).output().await.map_err(|error| format!("{program}: {error}"))?;

    if output.status.code().is_some_and(|code| accepted.contains(&code)) {
        Ok(())
    } else {
        Err(format!("{program} failed: {}", String::from_utf8_lossy(&output.stderr).trim()))
    }
}

async fn is_running(state: &AppState, slug: &str) -> bool {
    let container = managed(&state.docker, slug).await.ok().flatten();

    container.and_then(|container| container.state).and_then(|state| state.running) == Some(true)
}

// Stops the container if there is one and says whether it was running.
async fn stop(state: &AppState, slug: &str) -> Result<bool, String> {
    let was_running = is_running(state, slug).await;

    if was_running {
        let options = StopContainerOptionsBuilder::default().t(STOP_TIMEOUT_SECS).build();
        state.docker.stop_container(&container_name(slug), Some(options)).await.map_err(|error| error.to_string())?;
    }

    Ok(was_running)
}

async fn start(state: &AppState, slug: &str) -> Result<(), String> {
    state
        .docker
        .start_container(&container_name(slug), None::<StartContainerOptions>)
        .await
        .map_err(|error| error.to_string())
}

fn stored(dir: &FsPath) -> Vec<(u64, String, u64)> {
    let mut backups = Vec::new();

    for entry in std::fs::read_dir(dir).into_iter().flatten().flatten() {
        let name = entry.file_name().to_string_lossy().into_owned();
        let Ok(metadata) = entry.metadata() else { continue };

        if metadata.is_file() && valid_backup_name(&name) {
            let created = metadata.modified().ok().and_then(|time| time.duration_since(UNIX_EPOCH).ok());
            backups.push((created.map_or(0, |elapsed| elapsed.as_secs()), name, metadata.len()));
        }
    }

    // Newest first.
    backups.sort_by(|a, b| b.cmp(a));
    backups
}

// Runs tar so that it can be stopped half-way.
async fn tar(arguments: &[&OsStr], cancel: &Notify) -> Result<(), String> {
    let mut child = Command::new("tar")
        .args(arguments)
        .stdout(Stdio::null())
        .stderr(Stdio::piped())
        .kill_on_drop(true)
        .spawn()
        .map_err(|error| format!("tar: {error}"))?;
    let stderr = child.stderr.take();

    let status = tokio::select! {
        status = child.wait() => status.map_err(|error| format!("tar: {error}"))?,
        () = cancel.notified() => {
            let _ = child.kill().await;
            return Err(CANCELLED.into());
        }
    };

    // tar exits with 1 when a file changed while it was read, which a live server can still cause.
    if matches!(status.code(), Some(0 | 1)) {
        return Ok(());
    }

    let mut reason = String::new();
    if let Some(mut stderr) = stderr {
        let _ = stderr.read_to_string(&mut reason).await;
    }
    Err(format!("tar failed: {}", reason.trim()))
}

async fn archive(state: &AppState, slug: &str, keep: Option<usize>, by: Option<&str>) -> Result<(), String> {
    let dir = backup_dir(state, slug);
    std::fs::create_dir_all(&dir).map_err(|error| format!("backup folder: {error}"))?;

    // Pausing world saves keeps the files still while they are read; players can keep playing.
    let name = container_name(slug);
    let running = is_running(state, slug).await;
    if running {
        run_command(&state.docker, &name, "save-off").await;
        run_command(&state.docker, &name, "save-all flush").await;
    }

    let stamp = unix_now();
    let partial = dir.join(format!(".{stamp}.partial"));
    let target = dir.join(format!("{stamp}.tar.gz"));
    let source = server_dir(state, slug);
    let excludes: Vec<String> = EXCLUDED.iter().map(|folder| format!("--exclude=./{folder}")).collect();
    // Without the per-file warnings, a busy world cannot fill the pipe tar's messages are read from.
    let mut arguments: Vec<&OsStr> =
        vec!["--warning=no-file-changed".as_ref(), "-czf".as_ref(), partial.as_ref(), "-C".as_ref(), source.as_ref()];
    arguments.extend(excludes.iter().map(OsStr::new));
    arguments.push(".".as_ref());

    let cancel = Arc::new(Notify::new());
    state.cancels.lock().unwrap().insert(slug.to_owned(), cancel.clone());
    let archived = tar(&arguments, &cancel).await;
    state.cancels.lock().unwrap().remove(slug);

    if running {
        run_command(&state.docker, &name, "save-on").await;
    }

    if let Err(error) = archived {
        let _ = std::fs::remove_file(&partial);
        return Err(error);
    }
    std::fs::rename(&partial, &target).map_err(|error| format!("backup folder: {error}"))?;
    if let Some(by) = by {
        let _ = std::fs::write(dir.join(format!("{stamp}.by")), by);
    }

    if let Some(keep) = keep {
        let unlocked = stored(&dir).into_iter().filter(|(_, name, _)| !name.contains(".locked."));
        for (_, old, _) in unlocked.skip(keep.max(1)) {
            let _ = std::fs::remove_file(dir.join(format!("{}.by", self::stamp(&old))));
            let _ = std::fs::remove_file(dir.join(old));
        }
    }

    Ok(())
}

async fn restore(state: AppState, slug: String, backup: PathBuf) -> Result<(), String> {
    let was_running = stop(&state, &slug).await?;
    // The current state is archived first, so a restore can itself be undone.
    archive(&state, &slug, None, Some("Before a restore")).await?;

    let target = server_dir(&state, &slug);
    for entry in std::fs::read_dir(&target).map_err(|error| error.to_string())?.flatten() {
        if EXCLUDED.iter().any(|folder| entry.file_name() == *folder) {
            continue;
        }

        let path = entry.path();
        let removed = if entry.metadata().is_ok_and(|metadata| metadata.is_dir()) {
            std::fs::remove_dir_all(&path)
        } else {
            std::fs::remove_file(&path)
        };
        removed.map_err(|error| format!("{}: {error}", path.display()))?;
    }

    run("tar", &["-xzf".as_ref(), backup.as_ref(), "-C".as_ref(), target.as_ref()], &[0]).await?;

    if was_running {
        start(&state, &slug).await?;
    }

    Ok(())
}

// Looks a few levels deep for the folder that holds level.dat: zips often wrap the world in a folder.
fn find_world(dir: &FsPath, depth: u8) -> Option<PathBuf> {
    if dir.join("level.dat").is_file() {
        return Some(dir.to_path_buf());
    }
    if depth == 0 {
        return None;
    }

    std::fs::read_dir(dir)
        .ok()?
        .flatten()
        .filter(|entry| entry.metadata().is_ok_and(|metadata| metadata.is_dir()))
        .find_map(|entry| find_world(&entry.path(), depth - 1))
}

// ponytail: a zip is unpacked without a size cap, so a hostile archive can fill the disk; add a quota if untrusted users get this.
async fn import(state: AppState, slug: String, source: PathBuf, is_zip: bool, start_after: bool) -> Result<(), String> {
    let was_running = stop(&state, &slug).await?;
    archive(&state, &slug, None, Some("Before a world import")).await?;

    let target = server_dir(&state, &slug);
    let staging = target.join(".import");
    let _ = std::fs::remove_dir_all(&staging);
    std::fs::create_dir_all(&staging).map_err(|error| error.to_string())?;

    let unpacked = if is_zip {
        // unzip refuses entries that would land outside the folder it is given.
        run("unzip", &["-q".as_ref(), "-o".as_ref(), source.as_ref(), "-d".as_ref(), staging.as_ref()], &[0, 1]).await
    } else {
        run("cp", &["-a".as_ref(), source.as_ref(), staging.join("world").as_ref()], &[0]).await
    };

    let imported = match unpacked {
        Ok(()) => find_world(&staging, 3).ok_or_else(|| "No level.dat was found, so this is not a Minecraft world.".to_owned()),
        Err(error) => Err(error),
    };

    let result = match imported {
        Ok(world) => {
            for folder in WORLD_FOLDERS {
                let _ = std::fs::remove_dir_all(target.join(folder));
            }
            std::fs::rename(&world, target.join("world")).map_err(|error| error.to_string())
        }
        Err(error) => Err(error),
    };
    let _ = std::fs::remove_dir_all(&staging);
    result?;

    // The world must belong to the server's own user, whoever owned the source.
    if let Ok(owner) = target.metadata() {
        let owner = format!("{}:{}", owner.uid(), owner.gid());
        run("chown", &["-R".as_ref(), owner.as_ref(), target.join("world").as_ref()], &[0]).await?;
    }

    // An uploaded zip has done its job.
    if is_zip {
        let _ = std::fs::remove_file(&source);
    }

    if was_running || start_after {
        set_stopped(&state, &slug, false);
        start(&state, &slug).await?;
    }

    Ok(())
}

pub async fn list(State(state): State<AppState>, Path(slug): Path<String>) -> Result<Json<Value>, StatusCode> {
    if !valid_slug(&slug) {
        return Err(StatusCode::NOT_FOUND);
    }

    let dir = backup_dir(&state, &slug);
    let by = |name: &str| std::fs::read_to_string(dir.join(format!("{}.by", stamp(name)))).ok();
    let mut backups: Vec<(u64, Value)> = stored(&dir)
        .into_iter()
        .map(|(created, name, size)| {
            let entry = json!({
                "name": name, "size": size, "created": created, "locked": name.contains(".locked."),
                "status": "completed", "by": by(&name), "reason": null,
            });
            (created, entry)
        })
        .collect();

    let running = state.cancels.lock().unwrap().contains_key(&slug);
    for entry in std::fs::read_dir(&dir).into_iter().flatten().flatten() {
        let name = entry.file_name().to_string_lossy().into_owned();
        let started: u64 = stamp(&name).parse().unwrap_or(0);

        if valid_failure_name(&name) {
            let reason = std::fs::read_to_string(entry.path()).unwrap_or_default();
            backups.push((started, json!({
                "name": name, "size": 0, "created": started, "locked": false,
                "status": "failed", "by": by(&name), "reason": reason,
            })));
        } else if running && name.ends_with(".partial") {
            // The size so far lets the panel estimate progress against the previous backup.
            let size = entry.metadata().map_or(0, |metadata| metadata.len());
            backups.push((started, json!({
                "name": "running", "size": size, "created": started, "locked": false,
                "status": "running", "by": null, "reason": null,
            })));
        }
    }
    backups.sort_by(|a, b| b.0.cmp(&a.0));

    Ok(Json(Value::Array(backups.into_iter().map(|(_, entry)| entry).collect())))
}

#[derive(Deserialize)]
pub struct CreateBody {
    // How many unlocked backups to keep afterwards; none means keep all.
    keep: Option<usize>,
    // Who or what started it, for the list.
    by: Option<String>,
}

pub async fn create(State(state): State<AppState>, Path(slug): Path<String>, Json(body): Json<CreateBody>) -> StatusCode {
    if !valid_slug(&slug) || !server_dir(&state, &slug).is_dir() {
        return StatusCode::NOT_FOUND;
    }

    let by = body.by.filter(|by| by.chars().count() <= 80 && !by.chars().any(char::is_control));
    let tasks = state.tasks.clone();
    let task_slug = slug.clone();
    run_task(&tasks, slug, "backing up", async move {
        let started = unix_now();
        match archive(&state, &task_slug, body.keep, by.as_deref()).await {
            Err(reason) if reason != CANCELLED => {
                // A failed backup is a line in the list, not a broken server.
                let dir = backup_dir(&state, &task_slug);
                let _ = std::fs::write(dir.join(format!("{started}.failed")), &reason);
                if let Some(by) = &by {
                    let _ = std::fs::write(dir.join(format!("{started}.by")), by);
                }
            }
            _ => {}
        }
        Ok::<(), String>(())
    })
}

// Stops the backup that is running; what was written so far is thrown away.
pub async fn cancel(State(state): State<AppState>, Path(slug): Path<String>) -> StatusCode {
    match state.cancels.lock().unwrap().get(&slug) {
        Some(cancel) => {
            cancel.notify_one();
            StatusCode::ACCEPTED
        }
        None => StatusCode::NOT_FOUND,
    }
}

pub async fn restore_backup(State(state): State<AppState>, Path((slug, name)): Path<(String, String)>) -> Result<StatusCode, StatusCode> {
    let backup = backup_path(&state, &slug, &name)?;
    let tasks = state.tasks.clone();

    Ok(run_task(&tasks, slug.clone(), "restoring", restore(state, slug, backup)))
}

#[derive(Deserialize)]
pub struct LockBody {
    locked: bool,
}

pub async fn lock(
    State(state): State<AppState>,
    Path((slug, name)): Path<(String, String)>,
    Json(body): Json<LockBody>,
) -> Result<StatusCode, StatusCode> {
    let path = backup_path(&state, &slug, &name)?;
    let stamp = name.trim_end_matches(".tar.gz").trim_end_matches(".locked");
    let renamed = format!("{stamp}{}.tar.gz", if body.locked { ".locked" } else { "" });

    std::fs::rename(&path, path.with_file_name(renamed)).map_err(|_| StatusCode::UNPROCESSABLE_ENTITY)?;

    Ok(StatusCode::NO_CONTENT)
}

pub async fn delete(State(state): State<AppState>, Path((slug, name)): Path<(String, String)>) -> Result<StatusCode, StatusCode> {
    // The record of a failed backup is removed the same way as an archive.
    let dir = backup_dir(&state, &slug);
    let path = if valid_slug(&slug) && valid_failure_name(&name) && dir.join(&name).is_file() {
        dir.join(&name)
    } else {
        backup_path(&state, &slug, &name)?
    };

    std::fs::remove_file(path).map_err(|_| StatusCode::UNPROCESSABLE_ENTITY)?;
    let _ = std::fs::remove_file(dir.join(format!("{}.by", stamp(&name))));

    Ok(StatusCode::NO_CONTENT)
}

pub async fn download(
    State(state): State<AppState>,
    Path((slug, name)): Path<(String, String)>,
    Query(query): Query<HashMap<String, String>>,
) -> Result<Response, StatusCode> {
    let token = query.get("token").map(String::as_str).unwrap_or_default();
    claims(&state.token, "files", token, &slug, unix_now()).ok_or(StatusCode::UNAUTHORIZED)?;

    send_file(&backup_path(&state, &slug, &name)?, &format!("{slug}-{name}")).await
}

// Worlds in the import folder, as paths relative to it.
pub async fn list_imports(State(state): State<AppState>) -> Json<Value> {
    fn worlds(root: &FsPath, dir: &FsPath, depth: u8, found: &mut Vec<String>) {
        if dir.join("level.dat").is_file() {
            if let Ok(relative) = dir.strip_prefix(root) {
                found.push(relative.to_string_lossy().into_owned());
            }
        } else if depth > 0 {
            for entry in std::fs::read_dir(dir).into_iter().flatten().flatten() {
                if entry.metadata().is_ok_and(|metadata| metadata.is_dir()) {
                    worlds(root, &entry.path(), depth - 1, found);
                }
            }
        }
    }

    let root = FsPath::new(&*state.import_dir);
    let mut found = Vec::new();
    worlds(root, root, 3, &mut found);
    found.sort();

    Json(json!(found))
}

// A world folder inside the import folder, by its path relative to it.
pub fn host_world(state: &AppState, relative: &str) -> Result<PathBuf, StatusCode> {
    let root = FsPath::new(&*state.import_dir).canonicalize().map_err(|_| StatusCode::NOT_FOUND)?;
    let world = resolve_in(&root, relative)?;

    if world.join("level.dat").is_file() {
        Ok(world)
    } else {
        Err(StatusCode::NOT_FOUND)
    }
}

// Docker only makes the data folder when a container first starts, but files can be uploaded to a
// server that has not started yet, so the folder is made here, owned by the user Minecraft runs as.
pub async fn ensure_folder(state: &AppState, slug: &str) -> Result<(), String> {
    let folder = server_dir(state, slug);
    if folder.is_dir() {
        return Ok(());
    }

    std::fs::create_dir_all(&folder).map_err(|error| error.to_string())?;
    run("chown", &["1000:1000".as_ref(), folder.as_ref()], &[0]).await
}

// Gives a server that is about to be created a world from the import folder to start with.
pub async fn seed_world(state: &AppState, slug: &str, source: &FsPath) -> Result<(), String> {
    let target = server_dir(state, slug);
    std::fs::create_dir_all(&target).map_err(|error| error.to_string())?;
    // Never over a world that is already there, such as one left by a deleted server of the same name.
    if target.join("world").exists() {
        return Ok(());
    }

    run("cp", &["-a".as_ref(), source.as_ref(), target.join("world").as_ref()], &[0]).await?;
    // The image runs Minecraft as user 1000 unless told otherwise.
    run("chown", &["-R".as_ref(), "1000:1000".as_ref(), target.as_ref()], &[0]).await
}

#[derive(Deserialize)]
pub struct ImportBody {
    // "zip": a .zip inside the server's own folder; "host": a world folder inside the import folder.
    source: String,
    path: String,
    // Start the server afterwards even if it was not running, for a server created to receive this world.
    #[serde(default)]
    start: bool,
}

pub async fn import_world(
    State(state): State<AppState>,
    Path(slug): Path<String>,
    Json(body): Json<ImportBody>,
) -> Result<StatusCode, StatusCode> {
    if !valid_slug(&slug) {
        return Err(StatusCode::NOT_FOUND);
    }

    let (source, is_zip) = match body.source.as_str() {
        "zip" => {
            let server = server_dir(&state, &slug).canonicalize().map_err(|_| StatusCode::NOT_FOUND)?;
            let zip = resolve_in(&server, &body.path)?;
            if !zip.is_file() || !body.path.to_lowercase().ends_with(".zip") {
                return Err(StatusCode::NOT_FOUND);
            }
            (zip, true)
        }
        "host" => (host_world(&state, &body.path)?, false),
        _ => return Err(StatusCode::UNPROCESSABLE_ENTITY),
    };

    let tasks = state.tasks.clone();
    Ok(run_task(&tasks, slug.clone(), "importing", import(state, slug, source, is_zip, body.start)))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn only_archives_the_service_named_are_backups() {
        assert!(valid_backup_name("1794600000.tar.gz"));
        assert!(valid_backup_name("1794600000.locked.tar.gz"));
        assert!(valid_failure_name("1794600000.failed") && !valid_failure_name("../x.failed") && !valid_failure_name("1794600000.tar.gz"));
        assert_eq!(stamp("1794600000.locked.tar.gz"), "1794600000");
        assert_eq!(stamp(".1794600000.partial"), "1794600000");
        for bad in ["../1794600000.tar.gz", "world.tar.gz", "1794600000.zip", ".1794600000.partial", "1794600000.locked.locked.tar.gz", ".tar.gz"] {
            assert!(!valid_backup_name(bad), "{bad}");
        }
    }

    #[test]
    fn a_world_is_found_inside_a_wrapping_folder() {
        let base = std::env::temp_dir().join(format!("mcpanel-world-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&base);
        std::fs::create_dir_all(base.join("My Server/world/region")).unwrap();
        std::fs::write(base.join("My Server/world/level.dat"), "").unwrap();
        std::fs::create_dir_all(base.join("empty")).unwrap();

        assert_eq!(find_world(&base, 3), Some(base.join("My Server/world")));
        assert_eq!(find_world(&base, 1), None);
        assert_eq!(find_world(&base.join("empty"), 3), None);

        std::fs::remove_dir_all(&base).unwrap();
    }
}
