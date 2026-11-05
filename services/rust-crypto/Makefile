.PHONY: help build run test lint bench clean keys

help:
	@echo "OmniTix Crypto Engine"
	@echo "  make build     — compile release binary"
	@echo "  make run       — run in debug mode"
	@echo "  make test      — run tests"
	@echo "  make lint      — cargo clippy"
	@echo "  make bench     — run sign/verify benchmarks"
	@echo "  make keys      — generate dev keys in ./keys/"
	@echo "  make clean     — remove target/"

build:
	cargo build --release

run:
	cargo run

test:
	cargo test

lint:
	cargo clippy -- -D warnings

bench:
	cargo bench --bench sign_verify

keys:
	./scripts/gen-dev-keys.sh

clean:
	cargo clean