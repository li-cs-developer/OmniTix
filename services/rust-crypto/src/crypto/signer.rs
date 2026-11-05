use anyhow::{anyhow, Context, Result};
use base64::{engine::general_purpose::URL_SAFE_NO_PAD, Engine as _};
use ed25519_dalek::{Signature, Signer, Verifier};
use rand::RngCore;
use serde::{Deserialize, Serialize};

use super::canonical::canonical_json;
use super::keyring::Keyring;

/// TicketPayload is the canonical content of a ticket that gets signed.
///
/// Field names are single characters to keep QR codes small.
#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
pub struct TicketPayload {
    /// ticket_id (ULID)
    pub t: String,
    /// ticket_number
    pub n: String,
    /// event_id
    pub e: String,
    /// occurrence_id
    pub o: String,
    /// seat_id (empty string for GA tickets)
    #[serde(skip_serializing_if = "Option::is_none")]
    pub s: Option<String>,
    /// ga_zone_id (empty string for seat tickets)
    #[serde(skip_serializing_if = "Option::is_none")]
    pub g: Option<String>,
    /// price_tier_id
    pub p: String,
    /// buyer_user_id
    pub u: String,
    /// issued_at (RFC 3339, UTC)
    pub i: String,
    /// nonce (hex, 16 bytes)
    pub x: String,
}

impl TicketPayload {
    /// Generate a fresh random nonce for this payload.
    pub fn new_nonce() -> String {
        let mut bytes = [0u8; 16];
        rand::thread_rng().fill_bytes(&mut bytes);
        hex::encode(bytes)
    }
}

/// Signed ticket result returned by the signer.
#[derive(Debug, Clone)]
pub struct SignedTicket {
    /// Base64url-encoded canonical JSON of the payload.
    pub payload_b64: String,
    /// Base64url-encoded Ed25519 signature (64 bytes).
    pub signature_b64: String,
    /// Key ID used to sign.
    pub key_id: String,
    /// Full QR string: `<payload_b64>.<signature_b64>.<key_id>`
    pub qr_string: String,
}

/// Sign a ticket payload with the active key.
pub fn sign_ticket(keyring: &Keyring, payload: &TicketPayload) -> Result<SignedTicket> {
    // 1. Canonicalize
    let canonical = canonical_json(payload).context("canonicalizing payload")?;

    // 2. Sign
    let signing_key = keyring.active_signing_key();
    let signature: Signature = signing_key.sign(&canonical);

    // 3. Base64url encode both parts
    let payload_b64 = URL_SAFE_NO_PAD.encode(&canonical);
    let signature_b64 = URL_SAFE_NO_PAD.encode(signature.to_bytes());

    let key_id = keyring.active_key_id().to_string();
    let qr_string = format!("{}.{}.{}", payload_b64, signature_b64, key_id);

    Ok(SignedTicket {
        payload_b64,
        signature_b64,
        key_id,
        qr_string,
    })
}

/// Verify a QR string, returning the decoded payload on success.
pub fn verify_ticket(keyring: &Keyring, qr_string: &str) -> Result<TicketPayload> {
    // 1. Split into the three parts
    let parts: Vec<&str> = qr_string.split('.').collect();
    if parts.len() != 3 {
        return Err(anyhow!(
            "malformed QR: expected 3 dot-separated parts, got {}",
            parts.len()
        ));
    }
    let payload_b64 = parts[0];
    let signature_b64 = parts[1];
    let key_id = parts[2];

    // 2. Look up the verifying key
    let verifying_key = keyring
        .verifying_key(key_id)
        .ok_or_else(|| anyhow!("unknown key_id: {}", key_id))?;

    // 3. Decode
    let payload_bytes = URL_SAFE_NO_PAD
        .decode(payload_b64)
        .context("decoding payload base64")?;
    let signature_bytes = URL_SAFE_NO_PAD
        .decode(signature_b64)
        .context("decoding signature base64")?;

    if signature_bytes.len() != 64 {
        return Err(anyhow!(
            "signature must be 64 bytes, got {}",
            signature_bytes.len()
        ));
    }
    let mut sig_array = [0u8; 64];
    sig_array.copy_from_slice(&signature_bytes);
    let signature = Signature::from_bytes(&sig_array);

    // 4. Verify
    verifying_key
        .verify(&payload_bytes, &signature)
        .map_err(|e| anyhow!("signature verification failed: {}", e))?;

    // 5. Deserialize payload
    let payload: TicketPayload =
        serde_json::from_slice(&payload_bytes).context("deserializing payload")?;

    Ok(payload)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::crypto::keyring::Keyring;
    use std::path::PathBuf;

    fn test_keyring() -> Keyring {
        // Uses the real /keys dir. Tests assume it exists.
        Keyring::load(&PathBuf::from("keys"), "v1").expect("test keyring")
    }

    fn sample_payload() -> TicketPayload {
        TicketPayload {
            t: "01HTICKET".to_string(),
            n: "OTX-ABC123".to_string(),
            e: "01HEVENT".to_string(),
            o: "01HOCCURRENCE".to_string(),
            s: Some("01HSEAT".to_string()),
            g: None,
            p: "01HTIER".to_string(),
            u: "01HUSER".to_string(),
            i: "2026-10-02T12:00:00Z".to_string(),
            x: TicketPayload::new_nonce(),
        }
    }

    #[test]
    fn sign_and_verify_roundtrip() {
        let keyring = test_keyring();
        let payload = sample_payload();
        let signed = sign_ticket(&keyring, &payload).unwrap();
        let recovered = verify_ticket(&keyring, &signed.qr_string).unwrap();
        assert_eq!(recovered, payload);
    }

    #[test]
    fn tampered_payload_fails() {
        let keyring = test_keyring();
        let payload = sample_payload();
        let signed = sign_ticket(&keyring, &payload).unwrap();

        // Flip a character in the payload
        let mut parts: Vec<String> = signed.qr_string.split('.').map(String::from).collect();
        parts[0] = format!("A{}", &parts[0][1..]);
        let tampered = parts.join(".");

        assert!(verify_ticket(&keyring, &tampered).is_err());
    }

    #[test]
    fn malformed_qr_fails() {
        let keyring = test_keyring();
        assert!(verify_ticket(&keyring, "not.a.valid.qr").is_err());
        assert!(verify_ticket(&keyring, "only-one-part").is_err());
    }
}