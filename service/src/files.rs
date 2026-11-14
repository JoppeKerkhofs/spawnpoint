// The file manager: everything here works inside one server's data folder and nowhere else.
// ponytail: metadata calls block the request task; move them to spawn_blocking if folders get huge.

use std::{
    collections::HashMap,
    os::unix::fs::{chown, MetadataExt},
    path::{Path as FsPath, PathBuf},
    time::UNIX_EPOCH,
};

use axum::{
    body::{Body, Bytes},
    extract::{Path, Query, State},
    http::{header, StatusCode},
    response::{IntoResponse, Response},
    Json,
};
use futures_util::StreamExt;
use serde::Deserialize;
use serde_json::{json, Value};
use tokio::io::AsyncWriteExt;
use tokio_util::io::ReaderStream;

use crate::{claims, unix_now, valid_slug, AppState};

// Larger files are downloaded and uploaded, not edited.
const MAX_TEXT_BYTES: u64 = 1024 * 1024;
const MAX_UPLOAD_BYTES: u64 = 2 * 1024 * 1024 * 1024;

type Params = Query<HashMap<String, String>>;

fn param<'a>(query: &'a HashMap<String, String>, key: &str) -> &'a str {
    query.get(key).map(String::as_str).unwrap_or_default()
}

fn valid_component(part: &str) -> bool {
    !part.is_empty()
        && part.len() <= 255
        && part != "."
        && part != ".."
        && !part.contains(['/', '\\'])
        && !part.chars().any(char::is_control)
}

fn server_root(state: &AppState, slug: &str) -> Result<PathBuf, StatusCode> {
    if !valid_slug(slug) {
        return Err(StatusCode::NOT_FOUND);
    }

    FsPath::new(&*state.data_dir).join(slug).canonicalize().map_err(|_| StatusCode::NOT_FOUND)
}

// Turns a path from the panel into one inside the server's folder, refusing anything that could leave it.
// ponytail: checked then used, so a mod swapping in a symlink at that instant could win the race; use openat2 if that matters.
pub fn resolve(state: &AppState, slug: &str, relative: &str) -> Result<PathBuf, StatusCode> {
    let root = server_root(state, slug)?;
    let mut path = root.clone();

    for part in relative.split('/').filter(|part| !part.is_empty()) {
        if !valid_component(part) {
            return Err(StatusCode::BAD_REQUEST);
        }
        path.push(part);
    }

    // The deepest part that exists, symlinks included, must still resolve to somewhere inside the folder.
    let mut existing = path.as_path();
    while existing.symlink_metadata().is_err() {
        existing = existing.parent().ok_or(StatusCode::NOT_FOUND)?;
    }
    if !existing.canonicalize().map_err(|_| StatusCode::FORBIDDEN)?.starts_with(&root) {
        return Err(StatusCode::FORBIDDEN);
    }

    Ok(path)
}

// The service runs as root; new files go to the server's own user so Minecraft can still write them.
fn adopt(state: &AppState, slug: &str, path: &FsPath) {
    if let Ok(owner) = FsPath::new(&*state.data_dir).join(slug).metadata() {
        let _ = chown(path, Some(owner.uid()), Some(owner.gid()));
    }
}

fn io_status(error: std::io::Error) -> StatusCode {
    match error.kind() {
        std::io::ErrorKind::NotFound => StatusCode::NOT_FOUND,
        std::io::ErrorKind::AlreadyExists => StatusCode::CONFLICT,
        _ => StatusCode::UNPROCESSABLE_ENTITY,
    }
}

pub async fn list(State(state): State<AppState>, Path(slug): Path<String>, Query(query): Params) -> Result<Json<Value>, StatusCode> {
    let dir = resolve(&state, &slug, param(&query, "path"))?;
    let mut entries = Vec::new();

    for entry in std::fs::read_dir(&dir).map_err(io_status)?.flatten() {
        // Not following links: a link shows up as a small file, never as the folder it points to.
        let Ok(metadata) = entry.metadata() else { continue };
        let size = if metadata.is_dir() {
            std::fs::read_dir(entry.path()).map_or(0, |children| children.count() as u64)
        } else {
            metadata.len()
        };
        let modified = metadata.modified().ok().and_then(|time| time.duration_since(UNIX_EPOCH).ok());

        entries.push((
            !metadata.is_dir(),
            entry.file_name().to_string_lossy().into_owned(),
            size,
            modified.map_or(0, |elapsed| elapsed.as_secs()),
        ));
    }
    entries.sort();

    Ok(Json(Value::Array(
        entries
            .into_iter()
            .map(|(is_file, name, size, modified)| json!({ "name": name, "dir": !is_file, "size": size, "modified": modified }))
            .collect(),
    )))
}

pub async fn read_text(State(state): State<AppState>, Path(slug): Path<String>, Query(query): Params) -> Result<String, StatusCode> {
    let path = resolve(&state, &slug, param(&query, "path"))?;
    let metadata = path.metadata().map_err(io_status)?;

    if !metadata.is_file() {
        return Err(StatusCode::NOT_FOUND);
    }
    if metadata.len() > MAX_TEXT_BYTES {
        return Err(StatusCode::PAYLOAD_TOO_LARGE);
    }

    String::from_utf8(std::fs::read(&path).map_err(io_status)?).map_err(|_| StatusCode::UNSUPPORTED_MEDIA_TYPE)
}

pub async fn write_text(
    State(state): State<AppState>,
    Path(slug): Path<String>,
    Query(query): Params,
    body: Bytes,
) -> Result<StatusCode, StatusCode> {
    let path = resolve(&state, &slug, param(&query, "path"))?;
    if path.is_dir() || path.parent().is_none_or(|parent| !parent.is_dir()) {
        return Err(StatusCode::CONFLICT);
    }

    std::fs::write(&path, &body).map_err(io_status)?;
    adopt(&state, &slug, &path);

    Ok(StatusCode::NO_CONTENT)
}

#[derive(Deserialize)]
pub struct PathBody {
    path: String,
}

pub async fn mkdir(State(state): State<AppState>, Path(slug): Path<String>, Json(body): Json<PathBody>) -> Result<StatusCode, StatusCode> {
    let path = resolve(&state, &slug, &body.path)?;

    std::fs::create_dir(&path).map_err(io_status)?;
    adopt(&state, &slug, &path);

    Ok(StatusCode::NO_CONTENT)
}

#[derive(Deserialize)]
pub struct RenameBody {
    from: String,
    to: String,
}

pub async fn rename(State(state): State<AppState>, Path(slug): Path<String>, Json(body): Json<RenameBody>) -> Result<StatusCode, StatusCode> {
    let root = server_root(&state, &slug)?;
    let from = resolve(&state, &slug, &body.from)?;
    let to = resolve(&state, &slug, &body.to)?;

    if from == root || to == root {
        return Err(StatusCode::BAD_REQUEST);
    }
    if to.symlink_metadata().is_ok() {
        return Err(StatusCode::CONFLICT);
    }

    std::fs::rename(&from, &to).map_err(io_status)?;

    Ok(StatusCode::NO_CONTENT)
}

pub async fn delete(State(state): State<AppState>, Path(slug): Path<String>, Query(query): Params) -> Result<StatusCode, StatusCode> {
    let path = resolve(&state, &slug, param(&query, "path"))?;
    if path == server_root(&state, &slug)? {
        return Err(StatusCode::BAD_REQUEST);
    }

    // remove_dir_all does not follow links, so nothing outside the folder is touched.
    let metadata = path.symlink_metadata().map_err(io_status)?;
    if metadata.is_dir() {
        std::fs::remove_dir_all(&path).map_err(io_status)?;
    } else {
        std::fs::remove_file(&path).map_err(io_status)?;
    }

    Ok(StatusCode::NO_CONTENT)
}

// The browser sends uploads straight here with a files token; any origin may, since the token is the credential.
fn cross_origin(status: StatusCode) -> Response {
    (
        status,
        [
            (header::ACCESS_CONTROL_ALLOW_ORIGIN, "*"),
            (header::ACCESS_CONTROL_ALLOW_METHODS, "PUT"),
            (header::ACCESS_CONTROL_ALLOW_HEADERS, "content-type"),
        ],
    )
        .into_response()
}

pub async fn upload_preflight() -> Response {
    cross_origin(StatusCode::NO_CONTENT)
}

async fn store(target: &FsPath, body: Body) -> Result<(), StatusCode> {
    let mut file = tokio::fs::File::create(target).await.map_err(io_status)?;
    let mut chunks = body.into_data_stream();
    let mut written = 0u64;

    while let Some(chunk) = chunks.next().await {
        let chunk = chunk.map_err(|_| StatusCode::BAD_REQUEST)?;
        written += chunk.len() as u64;
        if written > MAX_UPLOAD_BYTES {
            return Err(StatusCode::PAYLOAD_TOO_LARGE);
        }
        file.write_all(&chunk).await.map_err(io_status)?;
    }

    file.flush().await.map_err(io_status)
}

pub async fn upload(State(state): State<AppState>, Path(slug): Path<String>, Query(query): Params, body: Body) -> Response {
    let allowed = claims(&state.token, "files", param(&query, "token"), &slug, unix_now()).is_some_and(|claims| claims.write);
    if !allowed {
        return cross_origin(StatusCode::UNAUTHORIZED);
    }

    let name = param(&query, "name");
    let target = match resolve(&state, &slug, &format!("{}/{name}", param(&query, "path"))) {
        Ok(target) if valid_component(name) && !target.is_dir() && target.parent().is_some_and(FsPath::is_dir) => target,
        Ok(_) => return cross_origin(StatusCode::CONFLICT),
        Err(status) => return cross_origin(status),
    };

    // Written under another name first, so a broken upload never replaces a good file.
    let partial = target.with_file_name(format!(".{name}.uploading"));
    let stored = match store(&partial, body).await {
        Ok(()) => tokio::fs::rename(&partial, &target).await.map_err(io_status),
        Err(status) => Err(status),
    };

    cross_origin(match stored {
        Ok(()) => {
            adopt(&state, &slug, &target);
            StatusCode::NO_CONTENT
        }
        Err(status) => {
            let _ = tokio::fs::remove_file(&partial).await;
            status
        }
    })
}

pub async fn download(State(state): State<AppState>, Path(slug): Path<String>, Query(query): Params) -> Result<Response, StatusCode> {
    claims(&state.token, "files", param(&query, "token"), &slug, unix_now()).ok_or(StatusCode::UNAUTHORIZED)?;

    let path = resolve(&state, &slug, param(&query, "path"))?;
    let file = tokio::fs::File::open(&path).await.map_err(io_status)?;
    let metadata = file.metadata().await.map_err(io_status)?;
    if !metadata.is_file() {
        return Err(StatusCode::NOT_FOUND);
    }

    let name: String = path
        .file_name()
        .map(|name| name.to_string_lossy().replace(['"', '\\'], "_"))
        .unwrap_or_default()
        .chars()
        .filter(char::is_ascii)
        .collect();

    Ok((
        [
            (header::CONTENT_TYPE, "application/octet-stream".to_owned()),
            (header::CONTENT_LENGTH, metadata.len().to_string()),
            (header::CONTENT_DISPOSITION, format!("attachment; filename=\"{name}\"")),
        ],
        Body::from_stream(ReaderStream::new(file)),
    )
        .into_response())
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::os::unix::fs::symlink;

    #[test]
    fn paths_cannot_leave_the_server_folder() {
        let base = std::env::temp_dir().join(format!("mcpanel-test-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&base);
        std::fs::create_dir_all(base.join("data/survival/mods")).unwrap();
        std::fs::create_dir_all(base.join("data/other")).unwrap();
        std::fs::write(base.join("secret"), "token").unwrap();
        symlink(base.join("secret"), base.join("data/survival/link-out")).unwrap();
        symlink(base.join("missing"), base.join("data/survival/dangling")).unwrap();
        symlink(base.join("data/other"), base.join("data/survival/dir-out")).unwrap();
        symlink(base.join("data/survival/mods"), base.join("data/survival/link-in")).unwrap();

        let state = AppState::for_tests(base.join("data").to_str().unwrap());
        let root = base.join("data/survival").canonicalize().unwrap();
        let check = |path: &str| resolve(&state, "survival", path);

        assert_eq!(check(""), Ok(root.clone()));
        assert_eq!(check("mods/new.jar"), Ok(root.join("mods/new.jar")));
        assert_eq!(check("/mods//new.jar"), Ok(root.join("mods/new.jar")));
        assert_eq!(check("link-in/new.jar"), Ok(root.join("link-in/new.jar")));
        assert_eq!(check("not/yet/there.txt"), Ok(root.join("not/yet/there.txt")));

        assert_eq!(check("../other"), Err(StatusCode::BAD_REQUEST));
        assert_eq!(check("mods/../../other"), Err(StatusCode::BAD_REQUEST));
        assert_eq!(check("mods\\..\\x"), Err(StatusCode::BAD_REQUEST));
        assert_eq!(check("link-out"), Err(StatusCode::FORBIDDEN));
        assert_eq!(check("dangling"), Err(StatusCode::FORBIDDEN));
        assert_eq!(check("dir-out/world.zip"), Err(StatusCode::FORBIDDEN));
        assert_eq!(resolve(&state, "../data/other", ""), Err(StatusCode::NOT_FOUND));
        assert_eq!(resolve(&state, "absent", ""), Err(StatusCode::NOT_FOUND));

        std::fs::remove_dir_all(&base).unwrap();
    }
}
