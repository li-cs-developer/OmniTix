use anyhow::Result;
use tracing_subscriber::{fmt, prelude::*, EnvFilter};

pub fn init(env: &str, level: &str) -> Result<()> {
    let filter = EnvFilter::try_from_default_env()
        .or_else(|_| EnvFilter::try_new(level))
        .unwrap_or_else(|_| EnvFilter::new("info"));

    let is_prod = env == "production" || env == "prod";

    let fmt_layer = if is_prod {
        fmt::layer()
            .json()
            .with_current_span(true)
            .with_span_list(false)
            .boxed()
    } else {
        fmt::layer()
            .with_target(true)
            .with_level(true)
            .with_thread_ids(false)
            .with_file(false)
            .with_line_number(false)
            .boxed()
    };

    tracing_subscriber::registry()
        .with(filter)
        .with(fmt_layer)
        .try_init()
        .ok(); // Ignore if already initialized

    Ok(())
}