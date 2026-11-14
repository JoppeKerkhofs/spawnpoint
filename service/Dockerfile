FROM rust:1-slim-bookworm AS build
WORKDIR /src
COPY Cargo.toml Cargo.lock ./
COPY src src
RUN --mount=type=cache,target=/usr/local/cargo/registry \
    --mount=type=cache,target=/src/target \
    cargo build --release --locked && cp target/release/mcpanel-service /mcpanel-service

FROM debian:bookworm-slim
COPY --from=build /mcpanel-service /usr/local/bin/mcpanel-service
EXPOSE 8080
ENTRYPOINT ["mcpanel-service"]
