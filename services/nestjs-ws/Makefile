.PHONY: help install build run dev test lint clean

help:
	@echo "OmniTix WS Gateway"
	@echo "  make install  — npm install"
	@echo "  make build    — compile TypeScript"
	@echo "  make dev      — run in watch mode"
	@echo "  make run      — run once"
	@echo "  make test     — jest"
	@echo "  make lint     — eslint"

install:
	npm install

build:
	npm run build

dev:
	npm run start:dev

run:
	npm run start

test:
	npm test

lint:
	npm run lint

clean:
	rm -rf dist node_modules