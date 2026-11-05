.PHONY: help build run test clean proto

help:
	@echo "OmniTix Ledger"
	@echo "  make build    — compile"
	@echo "  make run      — start the service"
	@echo "  make test     — run tests"
	@echo "  make proto    — regenerate protobuf stubs"
	@echo "  make clean    — clean build artifacts"

build:
	./gradlew build -x test

run:
	./gradlew bootRun

test:
	./gradlew test

proto:
	./gradlew generateProto

clean:
	./gradlew clean