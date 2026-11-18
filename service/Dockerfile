# syntax=docker/dockerfile:1
# The node: the Rust service that creates the Minecraft containers and handles their files and
# backups. It needs the Docker socket and the server folders of the host it runs on.

FROM rust:1-alpine AS build
# The TLS library behind rustls (aws-lc) is C and assembly, built with cmake.
RUN apk add --no-cache musl-dev cmake make gcc g++ perl linux-headers
WORKDIR /src
COPY Cargo.toml Cargo.lock ./
COPY src src
RUN --mount=type=cache,target=/usr/local/cargo/registry \
    --mount=type=cache,target=/src/target \
    cargo build --release --locked && cp target/release/spawnpoint-node /spawnpoint-node

FROM alpine:3
# The GNU tools the node calls, because Alpine's own short versions differ (its du has no -b):
# tar and gzip for backups, du for sizes, unzip for world imports, and curl with the
# certificates for https to fetch mods and a server moved from another node.
RUN apk add --no-cache tar gzip coreutils unzip curl ca-certificates
COPY --from=build /spawnpoint-node /usr/local/bin/spawnpoint-node
EXPOSE 8080
ENTRYPOINT ["spawnpoint-node"]
