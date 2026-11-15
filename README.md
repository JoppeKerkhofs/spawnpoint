# Spawnpoint

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
  `SPAWNPOINT_TOKEN` with long random strings, and the first admin's `PANEL_ADMIN_EMAIL` and
  `PANEL_ADMIN_PASSWORD`. Compose refuses to start while a required one is empty.
- Set `PANEL_BASE_DOMAIN` (for example `mc.example.com`) and point a wildcard DNS record
  (`*.mc.example.com`) at the host. Players join on port 25565, so forward that port.
- The panel listens on port 8080 (`APP_PORT`) and joins the external `proxy` network, so a
  TLS-terminating reverse proxy on that network reaches it as `spawnpoint-app:8080`. Set
  `APP_URL` to the `https://` address. `TRUSTED_PROXIES` decides which proxies are believed
  about https and the visitor's address; by default the private ranges.
- Always pass `--env-file .env.compose`.
- Update with `docker compose --env-file .env.compose pull` followed by `up -d`; migrations run
  when the app container starts. `docker compose --env-file .env.compose build` builds both
  images from this checkout instead.
- For invitations and password reset mails, fill in the `MAIL_` settings.

### Production

`compose.prod.yaml` is the same setup for a production host. It needs only that file and the
env file, not the repository:

```sh
docker compose -f compose.prod.yaml --env-file .env.compose up -d
```

- `PANEL_VERSION` is required (for example `1.3.0`), and both images are pulled, never built.
  To update, change it, then `pull` and `up -d`.
- The panel's port is bound to `127.0.0.1` only (`APP_BIND`); people reach it through the
  reverse proxy on the `proxy` network.
- Container logs are rotated (five files of 10 MB each), and the containers other than the
  node cannot gain new privileges.

Server data lives in `/srv/mc`, backups in `/srv/mc-backups`, and worlds you want to import go in
`/srv/mc-import`. The panel's own data is in the `database` volume.

## More hosts

Minecraft servers can run on more than one host. Each extra host runs a node, and the panel
tells it what to do:

1. In the panel, under Nodes, add the node with the address the host has for the panel, such as
   `http://10.0.0.5:8080`. The panel shows a token once.
2. On the host, copy `compose.node.yaml` and `.env.node.example`, save the example as `.env.node`
   and fill in the token, the version and `NODE_BIND`.
3. `docker compose -f compose.node.yaml --env-file .env.node up -d`

- When creating a server you choose its node; a server stays on the node it was created on.
- Players connect to the node's own host on port 25565, so each node needs its own public
  address. Set it per node as "Point DNS records at" for Cloudflare, or point DNS there yourself.
  The address of the web maps and the memory limit are per node too.
- The panel reaches a node over plain http and signs every request, but does not encrypt it.
  Put the panel and its nodes on a private network or a VPN, and never open the node's port to
  the internet.
- Browsers never talk to a node directly: the console and file transfers go through the panel.
- Keep nodes on the same version as the panel.

## Versions

The version is the `version` field of `panel/composer.json`. It is shown under the login form and
in Settings, so you can see which release is running. Every merge to `main` publishes both images
(`spawnpoint` and `spawnpoint-node`) as `latest` and under that version, so every pull request has to raise it; the pipelines check that
with `scripts/check-version.sh`.

To run a fixed release, set `PANEL_VERSION` in `.env.compose` (for example `1.2.0`); it pins both
images.

## Languages

The panel is written in English, and every text goes through a translation lookup with the
English text as the key. A language is one file, `panel/lang/<code>.json`:

```sh
cd panel
php artisan lang:sync nl   # creates or updates lang/nl.json with every text that has no translation yet
```

Translate the values in that file and set its `"@language"` entry to the language's own name.
An entry that still holds the English text is simply shown in English. Every file in `lang/`
shows up under Settings, Appearance, where each user picks their language; `APP_LOCALE` sets it
for people who have not chosen. Run the command again after an update to pick up new texts.

## Work on it

With PHP and Node on your machine, `compose.dev.yaml` runs only the node, the router and the map
proxy; the node listens on `localhost:8080`. In `panel/`:

```sh
composer setup
php artisan node:register local http://localhost:8080   # put the token it prints in ../.env as SPAWNPOINT_TOKEN
composer dev
```

Then, in this folder: `docker compose -f compose.dev.yaml up -d --build`.

Tests: `php artisan test` in `panel/`, `cargo test` in `service/`.
