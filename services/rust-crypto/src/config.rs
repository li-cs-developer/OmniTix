use anyhow::{Context, Result};
use serde::Deserialize;
use std::path::PathBuf;

#[derive(Debug, Deserialize, Clone)]
pub struct Config {
    #[serde(default = "default_env")]
    pub env: String,

    #[serde(default = "default_log_level")]
    pub log_level: String,

    #[serde(default = "default_grpc_port")]
    pub grpc_port: u16,

    #[serde(default = "default_http_port")]
    pub http_port: u16,

    #[serde(default = "default_key_id")]
    pub key_id: String,

    #[serde(default = "default_key_path")]
    pub key_path: PathBuf,

    #[serde(default = "default_max_extend_seconds")]
    pub max_extend_seconds: u64,
}

fn default_env() -> String { "local".to_string() }
fn default_log_level() -> String { "info".to_string() }
fn default_grpc_port() -> u16 { 50052 }
fn default_http_port() -> u16 { 50054 }
fn default_key_id() -> String { "v1".to_string() }
fn default_key_path() -> PathBuf { PathBuf::from("/keys") }
fn default_max_extend_seconds() -> u64 { 0 }

impl Config {
    pub fn load() -> Result<Self> {
        let _ = dotenvy::dotenv();
        envy::from_env::<Config>()
            .context("failed to load config from environment")
    }
}