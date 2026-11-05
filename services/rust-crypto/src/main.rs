use omnitix_crypto::{config, crypto, grpc, http, logger};

use anyhow::Result;
use std::sync::Arc;
use std::time::Duration;
use tokio::signal;
use tracing::{error, info};

#[tokio::main]
async fn main() -> Result<()> {
    let cfg = config::Config::load()?;

    logger::init(&cfg.env, &cfg.log_level)?;
    info!(
        version = "0.1.0",
        env = %cfg.env,
        grpc_port = cfg.grpc_port,
        http_port = cfg.http_port,
        key_id = %cfg.key_id,
        key_path = %cfg.key_path.display(),
        "OmniTix Crypto Engine starting"
    );

    let keyring = Arc::new(crypto::keyring::Keyring::load(&cfg.key_path, &cfg.key_id)?);
    info!(
        active_key_id = %keyring.active_key_id(),
        total_keys = keyring.key_count(),
        "keyring loaded"
    );

    let keyring_grpc = (*keyring).clone();
    let server = grpc::server::start(cfg.grpc_port, keyring_grpc, 0).await?;
    info!("gRPC server listening on :{}", cfg.grpc_port);

    let keyring_http = Arc::clone(&keyring);
    let http_port = cfg.http_port;
    let http_task = tokio::spawn(async move {
        if let Err(e) = http::server::run(http_port, keyring_http).await {
            error!(error = %e, "HTTP gateway failed");
        }
    });

    shutdown_signal().await;
    info!("received shutdown signal");

    info!("draining in-flight requests (5s)...");
    http_task.abort();

    tokio::select! {
        _ = server.stop() => {
            info!("gRPC server stopped gracefully");
        }
        _ = tokio::time::sleep(Duration::from_secs(5)) => {
            error!("graceful shutdown timed out");
        }
    }

    info!("goodbye 👋");
    Ok(())
}

async fn shutdown_signal() {
    let ctrl_c = async {
        signal::ctrl_c().await.expect("failed to install Ctrl+C handler");
    };

    #[cfg(unix)]
    let terminate = async {
        signal::unix::signal(signal::unix::SignalKind::terminate())
            .expect("failed to install SIGTERM handler")
            .recv()
            .await;
    };

    #[cfg(not(unix))]
    let terminate = std::future::pending::<()>();

    tokio::select! {
        _ = ctrl_c => {}
        _ = terminate => {}
    }
}