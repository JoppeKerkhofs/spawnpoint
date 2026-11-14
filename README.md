# minecraft-panel

A self-hosted web panel that creates and manages Minecraft Java servers, each in its own Docker
container. `panel/` is the Laravel and Vue panel; `service/` is the Rust service that does all
Docker and file work.

## Run it on a server

Needs Docker with the Compose plugin on a Linux host.

1. Copy `.env.example` to `.env`.
2. Fill in `MCPANEL_TOKEN` and `DB_PASSWORD` with long random strings, and the first admin's
   `PANEL_ADMIN_EMAIL` and `PANEL_ADMIN_PASSWORD`.
3. Generate the app key and put it in `APP_KEY`:

   ```sh
   docker compose build app
   docker compose run --rm --no-deps --entrypoint php app artisan key:generate --show
   ```

4. Set `PANEL_BASE_DOMAIN` (for example `mc.example.com`) and point a wildcard DNS record
   (`*.mc.example.com`) at the host. Players join on port 25565, so forward that port.
5. Start everything:

   ```sh
   docker compose up -d
   ```

The panel is on port 80 (`PANEL_PORT`), web maps on port 8100. Put a TLS proxy such as Cloudflare
in front of the panel and set `APP_URL` to its `https://` address.

Server data lives in `/srv/mc`, backups in `/srv/mc-backups`, and worlds you want to import go in
`/srv/mc-import`. Updating is `git pull`, then `docker compose up -d --build`.

## Work on it

With PHP and Node on your machine, set `COMPOSE_PROFILES=dev` in `.env`: `docker compose up -d`
then runs only the Rust service, the router and the map proxy. In `panel/`:

```sh
composer setup
php artisan node:register local http://localhost:8080   # put the token it prints in ../.env
composer dev
```

Tests: `php artisan test` in `panel/`, `cargo test` in `service/`.
