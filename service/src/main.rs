use std::{
    collections::HashMap,
    sync::Arc,
    time::{SystemTime, UNIX_EPOCH},
};

use axum::{
    body::{to_bytes, Body},
    extract::{Request, State},
    http::StatusCode,
    middleware::{self, Next},
    response::Response,
    routing::get,
    Json, Router,
};
use bollard::{query_parameters::ListContainersOptionsBuilder, Docker};
use hmac::{Hmac, KeyInit, Mac};
use serde_json::{json, Value};
use sha2::Sha256;

// Every action is refused on containers without this label.
const MANAGED_LABEL: &str = "mcpanel.managed=true";
const MAX_SKEW_SECS: u64 = 60;
// ponytail: bodies are buffered to verify the signature; file uploads will need a streamed scheme.
const MAX_BODY_BYTES: usize = 1024 * 1024;

#[derive(Clone)]
struct AppState {
    docker: Docker,
    token: Arc<[u8]>,
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
    let now = SystemTime::now().duration_since(UNIX_EPOCH).map_or(0, |elapsed| elapsed.as_secs());

    if !verify(&state.token, header("x-timestamp"), parts.method.as_str(), path, &body, header("x-signature"), now) {
        return Err(StatusCode::UNAUTHORIZED);
    }

    Ok(next.run(Request::from_parts(parts, Body::from(body))).await)
}

async fn list_containers(State(state): State<AppState>) -> Result<Json<Value>, StatusCode> {
    let filters = HashMap::from([("label", vec![MANAGED_LABEL])]);
    let options = ListContainersOptionsBuilder::default().all(true).filters(&filters).build();
    let containers = state.docker.list_containers(Some(options)).await.map_err(|error| {
        eprintln!("docker list failed: {error}");
        StatusCode::BAD_GATEWAY
    })?;

    Ok(Json(Value::Array(
        containers
            .into_iter()
            .map(|container| {
                json!({
                    "id": container.id,
                    "name": container.names.unwrap_or_default().first().map(|name| name.trim_start_matches('/').to_owned()),
                    "image": container.image,
                    "state": container.state,
                    "status": container.status,
                })
            })
            .collect(),
    )))
}

#[tokio::main]
async fn main() {
    let token = std::env::var("MCPANEL_TOKEN").unwrap_or_default();
    assert!(token.len() >= 32, "MCPANEL_TOKEN must be set to the node token (32+ characters)");
    let listen = std::env::var("MCPANEL_LISTEN").unwrap_or_else(|_| "0.0.0.0:8080".into());

    let state = AppState {
        docker: Docker::connect_with_local_defaults().expect("docker socket"),
        token: token.into_bytes().into(),
    };
    let app = Router::new()
        .route("/containers", get(list_containers))
        .layer(middleware::from_fn_with_state(state.clone(), require_signature))
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
}
