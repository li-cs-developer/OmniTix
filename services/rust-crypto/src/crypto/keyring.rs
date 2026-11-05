use anyhow::{anyhow, Context, Result};
use ed25519_dalek::{SigningKey, VerifyingKey};
use std::{
    collections::HashMap,
    fs,
    path::{Path, PathBuf},
    sync::{Arc, RwLock},
};

#[derive(Clone)]
pub struct Keyring {
    active_key_id: String,
    signing_keys: Arc<RwLock<HashMap<String, SigningKey>>>,
    verifying_keys: Arc<RwLock<HashMap<String, VerifyingKey>>>,
    key_dir: PathBuf,
}

impl Keyring {
    pub fn load(dir: &Path, active_key_id: &str) -> Result<Self> {
        let keyring = Self {
            active_key_id: active_key_id.to_string(),
            signing_keys: Arc::new(RwLock::new(HashMap::new())),
            verifying_keys: Arc::new(RwLock::new(HashMap::new())),
            key_dir: dir.to_path_buf(),
        };
        keyring.reload()?;
        Ok(keyring)
    }

    /// Re-read every `ed25519_<id>.private` file in `key_dir`, replacing the
    /// in-memory signing and verifying key maps atomically. Used both at
    /// startup and by the `rotate_key` gRPC handler.
    ///
    /// The maps are swapped under a write lock, so any concurrent `sign` or
    /// `verify` call either sees the old keys (if it holds a read lock) or
    /// the new keys (if it acquires one after the swap) — never a mix.
    pub fn reload(&self) -> Result<()> {
        let dir = &self.key_dir;
        if !dir.exists() {
            return Err(anyhow!("key directory does not exist: {}", dir.display()));
        }

        let mut new_signing = HashMap::new();
        let mut new_verifying = HashMap::new();

        for entry in fs::read_dir(dir).context("reading key directory")? {
            let entry = entry?;
            let path = entry.path();
            let name = match path.file_name().and_then(|s| s.to_str()) {
                Some(n) => n,
                None => continue,
            };

            let Some(key_id) = name
                .strip_prefix("ed25519_")
                .and_then(|s| s.strip_suffix(".private"))
            else {
                continue;
            };

            let signing_key = load_signing_key(&path, key_id)?;
            let verifying_key = signing_key.verifying_key();
            new_signing.insert(key_id.to_string(), signing_key);
            new_verifying.insert(key_id.to_string(), verifying_key);
        }

        if new_signing.is_empty() {
            return Err(anyhow!("no ed25519 keys found in {}", dir.display()));
        }

        if !new_signing.contains_key(&self.active_key_id) {
            return Err(anyhow!(
                "active key id '{}' not found (available: {:?})",
                self.active_key_id,
                new_signing.keys().collect::<Vec<_>>()
            ));
        }

        *self.signing_keys.write().unwrap() = new_signing;
        *self.verifying_keys.write().unwrap() = new_verifying;
        Ok(())
    }

    pub fn active_key_id(&self) -> &str {
        &self.active_key_id
    }

    pub fn key_count(&self) -> usize {
        self.signing_keys.read().unwrap().len()
    }

    pub fn signing_key(&self, key_id: &str) -> Option<SigningKey> {
        self.signing_keys.read().unwrap().get(key_id).cloned()
    }

    pub fn verifying_key(&self, key_id: &str) -> Option<VerifyingKey> {
        self.verifying_keys.read().unwrap().get(key_id).copied()
    }

    pub fn active_signing_key(&self) -> SigningKey {
        self.signing_keys
            .read()
            .unwrap()
            .get(&self.active_key_id)
            .cloned()
            .expect("active key checked at load time and after every reload")
    }

    pub fn public_keys(&self) -> Vec<PublicKeyInfo> {
        let verifying = self.verifying_keys.read().unwrap();
        let mut out: Vec<_> = verifying
            .iter()
            .map(|(id, vk)| PublicKeyInfo {
                key_id: id.clone(),
                algorithm: "Ed25519".to_string(),
                public_key_hex: hex::encode(vk.as_bytes()),
                active: *id == self.active_key_id,
            })
            .collect();
        out.sort_by(|a, b| a.key_id.cmp(&b.key_id));
        out
    }

    /// Directory the keyring was loaded from. Exposed so the gRPC handler can
    /// write a new keypair to disk before calling `reload()`.
    pub fn key_dir(&self) -> &Path {
        &self.key_dir
    }
}

#[derive(Debug, Clone)]
pub struct PublicKeyInfo {
    pub key_id: String,
    pub algorithm: String,
    pub public_key_hex: String,
    pub active: bool,
}

fn load_signing_key(path: &PathBuf, _key_id: &str) -> Result<SigningKey> {
    let raw = fs::read_to_string(path)
        .with_context(|| format!("reading key file {}", path.display()))?;
    let trimmed = raw.trim();

    let bytes = hex::decode(trimmed)
        .with_context(|| format!("decoding hex in {}", path.display()))?;

    if bytes.len() != 32 {
        return Err(anyhow!(
            "key file {} has {} bytes; expected 32",
            path.display(),
            bytes.len()
        ));
    }

    let mut seed = [0u8; 32];
    seed.copy_from_slice(&bytes);

    Ok(SigningKey::from_bytes(&seed))
}