# syntax=docker/dockerfile:1
# The node: the Rust service that creates the Minecraft containers and handles their files and
# backups. It needs the Docker socket and the server folders of the host it runs on.

FROM rust:1-slim-bookworm AS build
WORKDIR /src
COPY Cargo.toml Cargo.lock ./
COPY src src
RUN --mount=type=cache,target=/usr/local/cargo/registry \
    --mount=type=cache,target=/src/target \
    cargo build --release --locked && cp target/release/mcpanel-service /mcpanel-service

FROM debian:bookworm-slim
# World imports unpack .zip files; tar and gzip for backups are already in the base image.
RUN apt-get update && apt-get install -y --no-install-recommends unzip && rm -rf /var/lib/apt/lists/*
COPY --from=build /mcpanel-service /usr/local/bin/mcpanel-service
EXPOSE 8080
ENTRYPOINT ["mcpanel-service"]
