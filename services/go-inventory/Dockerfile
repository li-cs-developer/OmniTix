# ---------- build stage ----------
FROM golang:1.26-alpine AS build

RUN apk add --no-cache git ca-certificates

# Only buf is needed — contracts/proto/buf.gen.yaml uses remote plugins
RUN go install github.com/bufbuild/buf/cmd/buf@latest
ENV PATH="/go/bin:${PATH}"

WORKDIR /src

COPY services/go-inventory ./services/go-inventory
COPY contracts/proto     ./contracts/proto

# Generates into /src/services/go-inventory/gen/go per buf.gen.yaml
RUN cd contracts/proto && buf generate

WORKDIR /src/services/go-inventory
RUN go mod download
RUN CGO_ENABLED=0 go build -o /out/server ./cmd/server

# ---------- runtime ----------
FROM alpine:3.20
RUN apk add --no-cache ca-certificates
WORKDIR /app
COPY --from=build /out/server .
EXPOSE 50051 8080
ENTRYPOINT ["./server"]