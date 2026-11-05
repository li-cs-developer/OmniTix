use std::sync::Arc;
use tonic::{Request, Response, Status};
use tracing::{debug, warn};

use crate::crypto::keyring::Keyring;
use crate::crypto::signer::{sign_ticket, verify_ticket, TicketPayload};

use crate::generated::omnitix::crypto::v1::{
    crypto_service_server::CryptoService, GetPublicKeysRequest, GetPublicKeysResponse,
    HealthRequest, HealthResponse, PublicKey, RotateKeyRequest, RotateKeyResponse,
    SignTicketRequest, SignTicketResponse, VerifyTicketRequest, VerifyTicketResponse,
};
use crate::generated::omnitix::common::v1::{
    Result as ProtoResult, Status as ProtoStatus,
};

pub struct CryptoHandler {
    keyring: Arc<Keyring>,
}

impl CryptoHandler {
    pub fn new(keyring: Arc<Keyring>) -> Self {
        Self { keyring }
    }
}

#[tonic::async_trait]
impl CryptoService for CryptoHandler {
    async fn sign_ticket(
        &self,
        request: Request<SignTicketRequest>,
    ) -> Result<Response<SignTicketResponse>, Status> {
        let req = request.into_inner();
        let payload = req
            .payload
            .ok_or_else(|| Status::invalid_argument("missing payload"))?;

        // Convert proto TicketPayload → our internal TicketPayload
        let internal = TicketPayload {
            t: payload.ticket_id,
            n: payload.ticket_number,
            e: payload.event_id,
            o: payload.event_occurrence_id,
            s: if payload.seat_id.is_empty() { None } else { Some(payload.seat_id) },
            g: if payload.ga_zone_id.is_empty() { None } else { Some(payload.ga_zone_id) },
            p: payload.price_tier_id,
            u: payload.buyer_user_id,
            i: payload
                .issued_at
                .map(|ts| chrono::DateTime::<chrono::Utc>::from_timestamp(ts.seconds, ts.nanos as u32).unwrap_or_default())
                .map(|dt| dt.to_rfc3339())
                .unwrap_or_else(|| chrono::Utc::now().to_rfc3339()),
            x: payload.nonce,
        };

        let signed = sign_ticket(&self.keyring, &internal)
            .map_err(|e| Status::internal(format!("sign failed: {}", e)))?;

        debug!(
            ticket_id = %internal.t,
            key_id = %signed.key_id,
            "signed ticket"
        );

        Ok(Response::new(SignTicketResponse {
            result: Some(ok_result()),
            qr_payload: signed.payload_b64,
            signature: signed.signature_b64,
            key_id: signed.key_id,
            signed_at: Some(prost_types::Timestamp {
                seconds: chrono::Utc::now().timestamp(),
                nanos: 0,
            }),
        }))
    }

    async fn verify_ticket(
        &self,
        request: Request<VerifyTicketRequest>,
    ) -> Result<Response<VerifyTicketResponse>, Status> {
        let req = request.into_inner();
        let qr_string = format!("{}.{}.{}", req.qr_payload, req.signature, req.key_id);

        match verify_ticket(&self.keyring, &qr_string) {
            Ok(payload) => Ok(Response::new(VerifyTicketResponse {
                result: Some(ok_result()),
                valid: true,
                payload: Some(crate::generated::omnitix::crypto::v1::TicketPayload {
                    ticket_id: payload.t,
                    ticket_number: payload.n,
                    event_id: payload.e,
                    event_occurrence_id: payload.o,
                    seat_id: payload.s.unwrap_or_default(),
                    ga_zone_id: payload.g.unwrap_or_default(),
                    price_tier_id: payload.p,
                    buyer_user_id: payload.u,
                    issued_at: None,
                    nonce: payload.x,
                }),
                failure_reason: String::new(),
            })),
            Err(e) => {
                warn!(error = %e, "verification failed");
                Ok(Response::new(VerifyTicketResponse {
                    result: Some(ok_result()),
                    valid: false,
                    payload: None,
                    failure_reason: e.to_string(),
                }))
            }
        }
    }

    async fn get_public_keys(
        &self,
        _request: Request<GetPublicKeysRequest>,
    ) -> Result<Response<GetPublicKeysResponse>, Status> {
        let keys = self
            .keyring
            .public_keys()
            .into_iter()
            .map(|k| PublicKey {
                key_id: k.key_id,
                algorithm: k.algorithm,
                public_key: k.public_key_hex,
                active: k.active,
                created_at: None,
                retired_at: None,
            })
            .collect();

        Ok(Response::new(GetPublicKeysResponse {
            result: Some(ok_result()),
            keys,
        }))
    }

    async fn rotate_key(
        &self,
        request: Request<RotateKeyRequest>,
    ) -> Result<Response<RotateKeyResponse>, Status> {
        let req = request.into_inner();
        let new_key_id = req.new_key_id;

        if new_key_id.is_empty() {
            return Err(Status::invalid_argument("new_key_id is required"));
        }

        // Reject ids that wouldn't round-trip through the filename
        // convention (`ed25519_<id>.private`) or that would collide with
        // an existing key on disk.
        if new_key_id.contains('/') || new_key_id.contains('\\') || new_key_id.contains('.') {
            return Err(Status::invalid_argument(
                "new_key_id must not contain '/', '\\', or '.'",
            ));
        }

        let key_dir = self.keyring.key_dir();
        let private_path = key_dir.join(format!("ed25519_{}.private", new_key_id));
        let public_path = key_dir.join(format!("ed25519_{}.public", new_key_id));

        if private_path.exists() {
            return Err(Status::already_exists(format!(
                "key '{}' already exists",
                new_key_id
            )));
        }

        // Generate a fresh Ed25519 keypair.
        use ed25519_dalek::SigningKey;
        use rand::rngs::OsRng;
        use rand::RngCore;

        let mut seed = [0u8; 32];
        OsRng.fill_bytes(&mut seed);
        let signing_key = SigningKey::from_bytes(&seed);
        let verifying_key = signing_key.verifying_key();

        // Persist both files. Private is written first, then public, so a
        // crash mid-write leaves an orphan `.private` that `reload` will
        // still pick up (and the next rotate call for the same id will
        // fail with `already_exists`, prompting manual cleanup).
        std::fs::write(&private_path, hex::encode(signing_key.to_bytes()))
            .map_err(|e| Status::internal(format!("failed to write private key: {}", e)))?;
        std::fs::write(&public_path, hex::encode(verifying_key.to_bytes()))
            .map_err(|e| Status::internal(format!("failed to write public key: {}", e)))?;

        // Hot reload — the new key is now in the in-memory maps, available
        // for sign() and verify() immediately, without a restart.
        self.keyring
            .reload()
            .map_err(|e| Status::internal(format!("failed to reload keyring: {}", e)))?;

        debug!(
            key_id = %new_key_id,
            "rotated key; keyring reloaded"
        );

        Ok(Response::new(RotateKeyResponse {
            result: Some(ok_result()),
            new_key: Some(PublicKey {
                key_id: new_key_id,
                algorithm: "Ed25519".to_string(),
                public_key: hex::encode(verifying_key.to_bytes()),
                active: false, // the new key is not yet active — see note below
                created_at: None,
                retired_at: None,
            }),
        }))
    }

    async fn health(
        &self,
        _request: Request<HealthRequest>,
    ) -> Result<Response<HealthResponse>, Status> {
        let keys = self.keyring.public_keys();
        Ok(Response::new(HealthResponse {
            healthy: true,
            version: "0.1.0".to_string(),
            active_key_id: self.keyring.active_key_id().to_string(),
            total_keys: keys.len() as i32,
            checked_at: Some(prost_types::Timestamp {
                seconds: chrono::Utc::now().timestamp(),
                nanos: 0,
            }),
        }))
    }
}

fn ok_result() -> ProtoResult {
    ProtoResult {
        status: ProtoStatus::Ok as i32,
        error_code: String::new(),
        error_message: String::new(),
    }
}