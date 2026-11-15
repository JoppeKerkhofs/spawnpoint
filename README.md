# minecraft-panel

A self-hosted web panel that creates and manages Minecraft Java servers, each in its own Docker
container. `panel/` is the Laravel and Vue panel; `service/` is the Rust service that does all
Docker and file work.

## Run it on a server

Needs Docker with the Compose plugin on a Linux host. `compose.yaml` runs:

| Container | What it is |
| --- | --- |
| `app`, `queue`, `scheduler` | The panel image three times: the website, the worker that sends webhooks and mail, and the scheduler for backups |
| `db` | MySQL |
| `node` | The Rust service. The only container with the Docker socket and the server folders |
| `router` | Where players connect on port 25565; forwards to the server whose hostname they typed |
| `maps` | Serves each server's BlueMap on port 8100 |
| `socket-proxy` | Read-only container discovery for the router and the map proxy |

```sh
docker network create proxy            # once, if your reverse proxy has not created it
cp .env.compose.example .env.compose   # then fill it in
docker compose --env-file .env.compose up -d
```

- Fill in `APP_KEY` (the example file says how to generate one), `APP_URL`, `DB_PASSWORD` and
  `MCPANEL_TOKEN` with long random strings, and the first admin's `PANEL_ADMIN_EMAIL` and
  `PANEL_ADMIN_PASSWORD`. Compose refuses to start while a required one is empty.
- Set `PANEL_BASE_DOMAIN` (for example `mc.example.com`) and point a wildcard DNS record
  (`*.mc.example.com`) at the host. Players join on port 25565, so forward that port.
- The panel listens on port 8080 (`APP_PORT`) and joins the external `proxy` network, so a
  TLS-terminating reverse proxy on that network reaches it as `minecraft-panel-app:8080`. Set
  `APP_URL` to the `https://` address. `TRUSTED_PROXIES` decides which proxies are believed
  about https and the visitor's address; by default the private ranges.
- Always pass `--env-file .env.compose`.
- Update with `docker compose --env-file .env.compose pull` followed by `up -d`; migrations run
  when the app container starts. `docker compose --env-file .env.compose build` builds both
  images from this checkout instead.
- For invitations and password reset mails, fill in the `MAIL_` settings.

Server data lives in `/srv/mc`, backups in `/srv/mc-backups`, and worlds you want to import go in
`/srv/mc-import`. The panel's own data is in the `database` volume.

## Versions

The version is the `version` field of `panel/composer.json`. It is shown under the login form and
in Settings, so you can see which release is running. Every merge to `main` publishes both images
(`minecraft-panel` and `minecraft-panel-node`) as `latest` and under that version, so every pull request has to raise it; the pipelines check that
with `scripts/check-version.sh`.

To run a fixed release, set `PANEL_VERSION` in `.env.compose` (for example `1.2.0`); it pins both
images.

## Work on it

With PHP and Node on your machine, `compose.dev.yaml` runs only the node, the router and the map
proxy; the node listens on `localhost:8080`. In `panel/`:

```sh
composer setup
php artisan node:register local http://localhost:8080   # put the token it prints in ../.env as MCPANEL_TOKEN
composer dev
```

Then, in this folder: `docker compose -f compose.dev.yaml up -d --build`.

Tests: `php artisan test` in `panel/`, `cargo test` in `service/`.
