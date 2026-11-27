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
use sha2::{Digest, Sha512};
use tokio::io::AsyncWriteExt;
use tokio_util::io::ReaderStream;

use crate::{claims, unix_now, valid_slug, AppState};

// Larger files are downloaded and uploaded, not edited.
const MAX_TEXT_BYTES: u64 = 1024 * 1024;
const MAX_UPLOAD_BYTES: u64 = 2 * 1024 * 1024 * 1024;
// The name the Backups page uploads a world under, before asking for the import.
const IMPORT_NAME: &str = "world-import.zip";
// What players get is configuration: a larger file, or more of them, is left out.
const MAX_CLIENT_FILE_BYTES: u64 = 2 * 1024 * 1024;
const MAX_CLIENT_FILES: usize = 2000;

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
    resolve_in(&server_root(state, slug)?, relative)
}

// The same check for any folder; the root must already be canonical.
pub fn resolve_in(root: &FsPath, relative: &str) -> Result<PathBuf, StatusCode> {
    let mut path = root.to_path_buf();

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
    if !existing.canonicalize().map_err(|_| StatusCode::FORBIDDEN)?.starts_with(root) {
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

// Only a jar from Modrinth's own download host, under a plain file name.
fn valid_mod(url: &str, name: &str) -> bool {
    url.starts_with("https://cdn.modrinth.com/")
        && !url.chars().any(|character| character.is_control() || character.is_whitespace())
        && valid_component(name)
        && name.ends_with(".jar")
}

fn sha512(path: &FsPath) -> std::io::Result<String> {
    let mut file = std::fs::File::open(path)?;
    let mut hasher = Sha512::new();
    let mut buffer = [0u8; 65536];

    loop {
        let read = std::io::Read::read(&mut file, &mut buffer)?;
        if read == 0 {
            return Ok(hex::encode(hasher.finalize()));
        }
        hasher.update(&buffer[..read]);
    }
}

// The jars directly in mods/, each with the fingerprint Modrinth knows it by.
fn jar_list(root: &FsPath) -> Vec<Value> {
    let mut jars: Vec<(String, u64, String)> = std::fs::read_dir(root.join("mods"))
        .into_iter()
        .flatten()
        .flatten()
        .filter_map(|entry| {
            let name = entry.file_name().to_string_lossy().into_owned();
            let metadata = entry.metadata().ok()?;
            if !metadata.is_file() || !name.ends_with(".jar") {
                return None;
            }

            Some((name, metadata.len(), sha512(&entry.path()).ok()?))
        })
        .collect();
    jars.sort();

    jars.into_iter().map(|(name, size, sha512)| json!({ "name": name, "size": size, "sha512": sha512 })).collect()
}

pub async fn list_mods(State(state): State<AppState>, Path(slug): Path<String>) -> Result<Json<Value>, StatusCode> {
    let root = server_root(&state, &slug)?;
    // The mods folder must not be a link to somewhere else.
    resolve_in(&root, "mods")?;

    let jars = tokio::task::spawn_blocking(move || jar_list(&root)).await.map_err(|_| StatusCode::INTERNAL_SERVER_ERROR)?;

    Ok(Json(Value::Array(jars)))
}

#[derive(Deserialize)]
pub struct PathsBody {
    paths: Vec<String>,
}

// Every file at or under the given paths, each with its fingerprint. Links are not followed.
fn client_file_list(mut todo: Vec<(String, PathBuf)>) -> Vec<Value> {
    let mut files = std::collections::BTreeMap::new();

    while let Some((relative, path)) = todo.pop() {
        let Ok(metadata) = path.symlink_metadata() else { continue };
        if metadata.is_dir() {
            for entry in std::fs::read_dir(&path).into_iter().flatten().flatten() {
                todo.push((format!("{relative}/{}", entry.file_name().to_string_lossy()), entry.path()));
            }
        } else if metadata.is_file() && metadata.len() <= MAX_CLIENT_FILE_BYTES && files.len() < MAX_CLIENT_FILES {
            if let Ok(hash) = sha512(&path) {
                files.insert(relative, (metadata.len(), hash));
            }
        }
    }

    files.into_iter().map(|(path, (size, sha512))| json!({ "path": path, "size": size, "sha512": sha512 })).collect()
}

pub async fn client_files(State(state): State<AppState>, Path(slug): Path<String>, Json(body): Json<PathsBody>) -> Result<Json<Value>, StatusCode> {
    let mut start = Vec::new();
    for path in &body.paths {
        let relative = path.trim_matches('/');
        start.push((relative.to_owned(), resolve(&state, &slug, relative)?));
    }

    let files = tokio::task::spawn_blocking(move || client_file_list(start)).await.map_err(|_| StatusCode::INTERNAL_SERVER_ERROR)?;

    Ok(Json(Value::Array(files)))
}

#[derive(Deserialize)]
pub struct ModBody {
    url: String,
    name: String,
    sha512: String,
}

pub async fn install_mod(State(state): State<AppState>, Path(slug): Path<String>, Json(body): Json<ModBody>) -> Result<StatusCode, StatusCode> {
    if !valid_mod(&body.url, &body.name) {
        return Err(StatusCode::UNPROCESSABLE_ENTITY);
    }

    let folder = resolve(&state, &slug, "mods")?;
    if !folder.is_dir() {
        std::fs::create_dir(&folder).map_err(io_status)?;
        adopt(&state, &slug, &folder);
    }
    let target = resolve(&state, &slug, &format!("mods/{}", body.name))?;
    // curl writes through a link, and a mod could have left one here pointing outside the folder.
    let _ = tokio::fs::remove_file(folder.join(format!(".{}.downloading", body.name))).await;
    let partial = resolve(&state, &slug, &format!("mods/.{}.downloading", body.name))?;

    // No -L: the address was checked, a redirect could lead anywhere. -g: braces in it are not a pattern.
    // The panel waits 60 seconds, so this gives up first and never finishes behind its back.
    let fetched = tokio::process::Command::new("curl")
        .args(["-fsSg", "--proto", "=https", "--max-time", "50", "--max-filesize", "1073741824", "-o"])
        .arg(&partial)
        .arg("--")
        .arg(&body.url)
        .status()
        .await
        .is_ok_and(|status| status.success());
    let hashed = partial.clone();
    let intact = fetched
        && tokio::task::spawn_blocking(move || sha512(&hashed)).await.is_ok_and(|hash| hash.is_ok_and(|hash| hash.eq_ignore_ascii_case(&body.sha512)));

    if !intact || tokio::fs::rename(&partial, &target).await.is_err() {
        let _ = tokio::fs::remove_file(&partial).await;
        return Err(StatusCode::BAD_GATEWAY);
    }
    adopt(&state, &slug, &target);

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
    // A mod could have left a link under this name pointing outside the folder: create_new never writes through one.
    let _ = tokio::fs::remove_file(target).await;
    let mut file = tokio::fs::OpenOptions::new().write(true).create_new(true).open(target).await.map_err(io_status)?;
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

// A "files" token that may write uploads anywhere in the folder. An "import" token, which the
// Backups page gets, carries only the world zip to the top of the folder.
fn may_upload(key: &[u8], token: &str, slug: &str, path: &str, name: &str, now: u64) -> bool {
    claims(key, "files", token, slug, now).is_some_and(|claims| claims.write)
        || (path.is_empty() && name == IMPORT_NAME && claims(key, "import", token, slug, now).is_some())
}

pub async fn upload(State(state): State<AppState>, Path(slug): Path<String>, Query(query): Params, body: Body) -> Response {
    let (path, name) = (param(&query, "path"), param(&query, "name"));
    if !may_upload(&state.token, param(&query, "token"), &slug, path, name, unix_now()) {
        return cross_origin(StatusCode::UNAUTHORIZED);
    }

    let target = match resolve(&state, &slug, &format!("{path}/{name}")) {
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
    let name = path.file_name().map(|name| name.to_string_lossy().into_owned()).unwrap_or_default();

    send_file(&path, &name).await
}

// Streams a file as a download, so its size never matters for memory.
pub async fn send_file(path: &FsPath, name: &str) -> Result<Response, StatusCode> {
    let file = tokio::fs::File::open(path).await.map_err(io_status)?;
    let metadata = file.metadata().await.map_err(io_status)?;
    if !metadata.is_file() {
        return Err(StatusCode::NOT_FOUND);
    }

    let name: String = name.replace(['"', '\\'], "_").chars().filter(char::is_ascii).collect();

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
    use hmac::{Hmac, KeyInit, Mac};
    use sha2::Sha256;
    use std::os::unix::fs::symlink;

    #[test]
    fn paths_cannot_leave_the_server_folder() {
        let base = std::env::temp_dir().join(format!("spawnpoint-test-{}", std::process::id()));
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

    #[tokio::test]
    async fn an_upload_never_writes_through_a_link_left_under_its_temporary_name() {
        let base = std::env::temp_dir().join(format!("spawnpoint-upload-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&base);
        std::fs::create_dir_all(&base).unwrap();
        std::fs::write(base.join("secret"), "token").unwrap();
        let partial = base.join(".x.uploading");
        symlink(base.join("secret"), &partial).unwrap();

        assert_eq!(store(&partial, Body::from("evil")).await, Ok(()));
        assert_eq!(std::fs::read_to_string(base.join("secret")).unwrap(), "token");
        assert!(partial.symlink_metadata().unwrap().is_file());
        assert_eq!(std::fs::read_to_string(&partial).unwrap(), "evil");

        std::fs::remove_dir_all(&base).unwrap();
    }

    #[test]
    fn an_import_token_uploads_the_world_zip_and_nothing_else() {
        let key = b"0123456789abcdef0123456789abcdef";
        let token = |purpose: &str, write: bool| {
            let payload = hex::encode(format!(r#"{{"slug":"survival","exp":2000,"write":{write}}}"#));
            let mut mac = Hmac::<Sha256>::new_from_slice(key).unwrap();
            mac.update(format!("{purpose}\n{payload}").as_bytes());
            format!("{payload}.{}", hex::encode(mac.finalize().into_bytes()))
        };
        let (files, read, import) = (token("files", true), token("files", false), token("import", true));

        assert!(may_upload(key, &files, "survival", "plugins", "a.jar", 1000));
        assert!(!may_upload(key, &read, "survival", "plugins", "a.jar", 1000));
        assert!(may_upload(key, &import, "survival", "", "world-import.zip", 1000));
        assert!(!may_upload(key, &import, "survival", "", "a.jar", 1000));
        assert!(!may_upload(key, &import, "survival", "plugins", "world-import.zip", 1000));
        assert!(!may_upload(key, &import, "creative", "", "world-import.zip", 1000));
        assert!(!may_upload(key, &import, "survival", "", "world-import.zip", 2001));
    }

    #[test]
    fn files_for_players_are_listed_under_what_was_chosen() {
        let base = std::env::temp_dir().join(format!("spawnpoint-client-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&base);
        std::fs::create_dir_all(base.join("config/create")).unwrap();
        std::fs::write(base.join("config/create/a.toml"), "abc").unwrap();
        std::fs::write(base.join("config/one.toml"), "abc").unwrap();
        std::fs::write(base.join("config/other.toml"), "no").unwrap();
        std::fs::write(base.join("secret"), "token").unwrap();
        symlink(base.join("secret"), base.join("config/create/link")).unwrap();

        let chosen = |path: &str| (path.to_owned(), base.join(path));
        let listed = client_file_list(vec![chosen("config/create"), chosen("config/one.toml"), chosen("config/gone")]);
        let paths: Vec<&str> = listed.iter().map(|file| file["path"].as_str().unwrap()).collect();

        assert_eq!(paths, vec!["config/create/a.toml", "config/one.toml"]);
        assert_eq!(listed[0]["size"], 3);
        assert_eq!(listed[0]["sha512"].as_str().unwrap().len(), 128);

        std::fs::remove_dir_all(&base).unwrap();
    }

    #[test]
    fn mod_downloads_are_limited_to_modrinth_jars() {
        assert!(valid_mod("https://cdn.modrinth.com/data/a/b.jar", "b.jar"));
        assert!(!valid_mod("http://cdn.modrinth.com/data/a/b.jar", "b.jar"));
        assert!(!valid_mod("https://cdn.modrinth.com.evil.example/x.jar", "x.jar"));
        assert!(!valid_mod("https://cdn.modrinth.com/a.jar -o /etc/passwd", "a.jar"));
        assert!(!valid_mod("https://cdn.modrinth.com/data/a/b.jar", "../b.jar"));
        assert!(!valid_mod("https://cdn.modrinth.com/data/a/b.jar", "a/b.jar"));
        assert!(!valid_mod("https://cdn.modrinth.com/data/a/b.jar", "b.zip"));
    }

    #[test]
    fn jars_are_listed_with_their_fingerprint() {
        let base = std::env::temp_dir().join(format!("spawnpoint-mods-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&base);
        std::fs::create_dir_all(base.join("survival/mods")).unwrap();
        std::fs::create_dir_all(base.join("empty")).unwrap();
        std::fs::write(base.join("survival/mods/a.jar"), "abc").unwrap();
        std::fs::write(base.join("survival/mods/notes.txt"), "no").unwrap();

        assert_eq!(
            jar_list(&base.join("survival")),
            vec![json!({
                "name": "a.jar",
                "size": 3,
                "sha512": "ddaf35a193617abacc417349ae20413112e6fa4e89a97ea20a9eeee64b55d39a2192992a274fc1a836ba3c23a3feebbd454d4423643ce80e2a9ac94fa54ca49f",
            })]
        );
        assert!(jar_list(&base.join("empty")).is_empty());

        std::fs::remove_dir_all(&base).unwrap();
    }
}
