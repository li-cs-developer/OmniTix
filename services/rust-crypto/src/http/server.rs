use std::sync::Arc;
use axum::{
    extract::State,
    http::StatusCode,
    routing::{get, post},
    Json, Router,
};
use base64::{engine::general_purpose::URL_SAFE_NO_PAD, Engine as _};
use serde::{Deserialize, Serialize};
use tracing::{error, info};

use crate::crypto::keyring::Keyring;
use crate::crypto::signer::{sign_ticket, verify_ticket, TicketPayload};

#[derive(Clone)]
pub struct AppState {
    pub keyring: Arc<Keyring>,
}

pub async fn run(port: u16, keyring: Arc<Keyring>) -> anyhow::Result<()> {
    let state = AppState { keyring };

    let app = Router::new()
        .route("/api/v1/health", get(health))
        .route("/api/v1/sign", post(sign_handler))
        .route("/api/v1/verify", post(verify_handler))
        .route("/.well-known/omnitix-keys.json", get(jwks))
        .with_state(state);

    let addr = format!("0.0.0.0:{}", port);
    info!(%addr, "HTTP gateway listening");

    let listener = tokio::net::TcpListener::bind(&addr).await?;
    axum::serve(listener, app).await?;

    Ok(())
}

async fn health(State(state): State<AppState>) -> Json<serde_json::Value> {
    let keys = state.keyring.public_keys();
    Json(serde_json::json!({
        "status": "ok",
        "service": "rust-crypto",
        "active_key_id": state.keyring.active_key_id(),
        "total_keys": keys.len(),
    }))
}

/// JWKS-style public key distribution.
///
/// Shape matches the IETF JWKS convention so that any standard JOSE
/// verifier can consume it after fetching — with one wrinkle: Ed25519
/// keys use the `OKP` kty and `Ed25519` curve (RFC 8037), and we expose
/// both the raw hex and the base64url raw key for convenience.
async fn jwks(State(state): State<AppState>) -> Json<serde_json::Value> {
    let now = chrono::Utc::now().to_rfc3339();
    let keys: Vec<serde_json::Value> = state
        .keyring
        .public_keys()
        .into_iter()
        .map(|k| {
            // Public key bytes as hex → raw bytes → base64url (unpadded)
            let bytes = hex::decode(&k.public_key_hex).unwrap_or_default();
            let b64u = URL_SAFE_NO_PAD.encode(&bytes);
            serde_json::json!({
                "kid": k.key_id,
                "kty": "OKP",
                "crv": "Ed25519",
                "alg": "EdDSA",
                "use": "sig",
                "x": b64u,
                "public_key_hex": k.public_key_hex,
                "active": k.active,
            })
        })
        .collect();

    Json(serde_json::json!({
        "keys": keys,
        "generated_at": now,
        "issuer": "omnitix",
        "purpose": "ticket_verification",
    }))
}

#[derive(Debug, Deserialize)]
struct SignRequest {
    ticket_id: String,
    ticket_number: String,
    event_id: String,
    event_occurrence_id: String,
    #[serde(default)]
    seat_id: String,
    #[serde(default)]
    ga_zone_id: String,
    price_tier_id: String,
    #[serde(default)]
    buyer_user_id: String,
    issued_at: String,
    nonce: String,
}

#[derive(Debug, Serialize)]
struct SignResponse {
    qr_payload: String,
    signature: String,
    key_id: String,
}

async fn sign_handler(
    State(state): State<AppState>,
    Json(req): Json<SignRequest>,
) -> Result<Json<SignResponse>, (StatusCode, Json<serde_json::Value>)> {
    let payload = TicketPayload {
        t: req.ticket_id,
        n: req.ticket_number,
        e: req.event_id,
        o: req.event_occurrence_id,
        s: if req.seat_id.is_empty() { None } else { Some(req.seat_id) },
        g: if req.ga_zone_id.is_empty() { None } else { Some(req.ga_zone_id) },
        p: req.price_tier_id,
        u: req.buyer_user_id,
        i: req.issued_at,
        x: req.nonce,
    };

    match sign_ticket(&state.keyring, &payload) {
        Ok(signed) => {
            info!(ticket_id = %payload.t, key_id = %signed.key_id, "signed ticket");
            Ok(Json(SignResponse {
                qr_payload: signed.payload_b64,
                signature: signed.signature_b64,
                key_id: signed.key_id,
            }))
        }
        Err(e) => {
            error!(error = %e, "sign failed");
            Err((
                StatusCode::INTERNAL_SERVER_ERROR,
                Json(serde_json::json!({"error": "sign_failed", "message": e.to_string()})),
            ))
        }
    }
}

#[derive(Debug, Deserialize)]
struct VerifyRequest {
    qr_string: String,
}

async fn verify_handler(
    State(state): State<AppState>,
    Json(req): Json<VerifyRequest>,
) -> Json<serde_json::Value> {
    match verify_ticket(&state.keyring, &req.qr_string) {
        Ok(payload) => Json(serde_json::json!({
            "valid": true,
            "payload": {
                "ticket_id": payload.t,
                "ticket_number": payload.n,
            }
        })),
        Err(e) => Json(serde_json::json!({
            "valid": false,
            "failure_reason": e.to_string(),
        })),
    }
}