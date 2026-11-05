use anyhow::{Context, Result};
use std::sync::Arc;
use tonic::transport::Server;
use tracing::info;

use super::handlers::CryptoHandler;
use crate::crypto::keyring::Keyring;
use crate::generated::omnitix::crypto::v1::crypto_service_server::CryptoServiceServer;

pub struct RunningServer {
    shutdown_tx: tokio::sync::oneshot::Sender<()>,
    handle: tokio::task::JoinHandle<()>,
}

impl RunningServer {
    pub async fn stop(self) -> Result<()> {
        let _ = self.shutdown_tx.send(());
        let _ = self.handle.await;
        Ok(())
    }
}

pub async fn start(
    port: u16,
    keyring: Keyring,
    _unused: u64,
) -> Result<RunningServer> {
    let addr = format!("0.0.0.0:{}", port).parse().context("parse listen addr")?;

    let handler = CryptoHandler::new(Arc::new(keyring));
    let service = CryptoServiceServer::new(handler);

    let (tx, rx) = tokio::sync::oneshot::channel::<()>();

    info!(%addr, "starting gRPC server");

    // We need the reflection service too
    let reflection = tonic_reflection::server::Builder::configure()
        .register_encoded_file_descriptor_set(crate::generated::FILE_DESCRIPTOR_SET)
        .build_v1()
        .context("building reflection service")?;

    let handle = tokio::spawn(async move {
        Server::builder()
            .add_service(reflection)
            .add_service(service)
            .serve_with_shutdown(addr, async {
                let _ = rx.await;
            })
            .await
            .unwrap_or_else(|e| {
                tracing::error!(error = %e, "gRPC server error");
            });
    });

    Ok(RunningServer {
        shutdown_tx: tx,
        handle,
    })
}