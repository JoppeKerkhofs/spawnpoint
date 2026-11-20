# Spawnpoint

A self-hosted web panel that creates and manages Minecraft Java servers, each in its own Docker
container. `panel/` is the Laravel and Vue panel; `service/` is the Rust service that does all
Docker and file work.

## Run it on a server

Needs Docker with the Compose plugin on a Linux host. `compose.yaml` runs:

| Container | What it is |
| --- | --- |
| `app`, `queue`, `scheduler` | The panel image three times: the website, the worker that sends webhooks and mail, and the scheduler for backups and modpack updates |
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

- It runs the release the file belongs to, never `latest`, and both images are pulled, never
  built. To update, get the new `compose.prod.yaml`, then `pull` and `up -d`.
- The panel's port is bound to `127.0.0.1` only (`APP_BIND`); people reach it through the
  reverse proxy on the `proxy` network.
- Container logs are rotated (five files of 10 MB each), and the containers other than the
  node cannot gain new privileges.

Server data lives in `/srv/mc`, backups in `/srv/mc-backups`, and worlds you want to import go in
`/srv/mc-import`. The panel's own data is in the `database` volume.

To keep backups somewhere else, such as a NAS or an SMB share mounted on the host, set
`SPAWNPOINT_BACKUP_DIR` in the node's `.env` to that folder; each server gets its own folder in
it. Set `SPAWNPOINT_BACKUP_FALLBACK_DIR` to a folder on another disk (`/srv/mc-backups`, say) and
backups are saved there while the first cannot be reached: the share is down, or it is not
mounted, which the node sees because both folders are then on the same disk. Each such backup is
recorded in the activity log, reported to webhooks that follow backups, and marked in the list;
it stays in the fallback folder. Settings shows per node which folder it uses and whether it can
be reached. A share mounted after the node started is only seen once the node is restarted.

## More hosts

Minecraft servers can run on more than one host. Each extra host runs a node, and the panel
tells it what to do:

1. In the panel, under Nodes, add the node with the address the host has for the panel, such as
   `http://10.0.0.5:8080`. The panel shows a token once.
2. On the host, copy `compose.node.yaml` and `.env.node.example`, save the example as `.env.node`
   and fill in the token, the version and `NODE_BIND`.
3. `docker compose -f compose.node.yaml --env-file .env.node up -d`

- When creating a server you choose its node. Under "Move to another node" on a server's
  configuration page it can be handed over later: the panel stops it, backs it up, the new node
  downloads that archive and unpacks it, and only then is the old container given up. The data
  folder on the old host stays behind, as it does when a server is deleted. A big world takes a
  while; the activity log says when the move is done or why it was not.
- A node needs no public address. While "Point DNS records at" is empty for a node, its servers
  keep the panel's address: players connect to the panel's host, whose router passes them on to
  the node on port 25565. Fill it in, and players connect to the node's own host. The address
  of the web maps works the same way, on port 8100. Both ports of the node must be reachable
  from the panel's host. A node whose `MINECRAFT_PORT` or `BLUEMAP_PORT` differs gets those
  ports filled in on the Nodes page. The memory limit is per node too.
- The panel signs every request to a node. Over plain http it does not encrypt it, so put the
  panel and its nodes on a private network or a VPN, and never open the node's port to the
  internet. See "Encrypted panel traffic" below to use https instead.
- Browsers never talk to a node directly: the console and file transfers go through the panel.
- Keep nodes on the same version as the panel.

### Encrypted panel traffic

On a single host the panel and its node talk over Docker's own network and nothing leaves the
machine. A node on another host can be given a certificate, and then speaks https instead of
http, so the traffic between the two is encrypted as well as signed:

1. On the node's host, put a certificate and key for the name the panel uses in `./tls` as
   `node.crt` and `node.key`, signed by a public authority or by your own:

   ```sh
   mkdir -p tls && openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
     -keyout tls/node.key -out tls/node.crt -subj "/CN=node1.example.com" \
     -addext "subjectAltName=DNS:node1.example.com"
   ```

2. Uncomment the `./tls:/tls:ro` volume in `compose.node.yaml`, set `SPAWNPOINT_TLS_CERT` and
   `SPAWNPOINT_TLS_KEY` in `.env.node`, and bring the node up again. It then serves the same
   port over TLS; the log line says `with TLS`.
3. In the panel, give the node an `https://` address. Browser traffic for the console and file
   transfers goes through the panel, which uses the same address, so it is encrypted too.

The panel has to trust whoever signed the certificate. For a certificate of your own, give the
panel a certificate bundle with yours added:

```sh
docker run --rm ghcr.io/joppekerkhofs/spawnpoint:latest cat /etc/ssl/certs/ca-certificates.crt > bundle.crt
cat node-ca.crt >> bundle.crt
```

and mount it in the `app`, `queue` and `scheduler` containers as
`./bundle.crt:/etc/ssl/certs/ca-certificates.crt:ro`.

## Versions

The version is the `version` field of `panel/composer.json`. It is shown under the login form and
in Settings, so you can see which release is running. Every merge to `main` publishes both images
(`spawnpoint` and `spawnpoint-node`) as `latest` and under that version, so every pull request has to raise it; the pipelines check that
with `scripts/check-version.sh`.

`compose.prod.yaml` and `compose.node.yaml` name that same version as the release they run, so
there is no version to fill in; a test keeps them equal to `composer.json`. To run another
release, set `PANEL_VERSION` in the env file (for example `1.2.0`); it pins both images.

## Server pictures

On a server's Configuration page you can upload a picture. Players see it in their server list
after the next restart, and the panel shows it in its own lists. Any image works: the browser
cuts it to a square and scales it to the 64 by 64 pixels Minecraft wants. A modpack server shows
its pack's picture until you upload one.

## Web maps behind your proxy

The `maps` container serves every server's BlueMap over plain http on port 8100, open to
anyone. To give the maps https and a name of their own, let your reverse proxy serve them: the
container is on the `proxy` network as `spawnpoint-maps`, so forward a host name (for example
`maps.example.com`) to `http://spawnpoint-maps:8100`, with the path unchanged. Then set
`PANEL_BLUEMAP_URL=https://maps.example.com` and `BLUEMAP_BIND=127.0.0.1` in `.env.compose`, so
the port is no longer open beside the proxy. `APP_BIND=127.0.0.1` does the same for the panel.

## Live updates

Pages update by themselves: the panel tells open pages over a WebSocket that something changed,
and the page fetches that part again. The WebSocket server is the `realtime` container, the same
image as the panel. It has no port of its own: browsers reach it through the panel's address,
at `/app/...`, so a reverse proxy in front of the panel has to pass WebSocket upgrades on (Caddy
and Traefik do; nginx needs the `Upgrade` and `Connection` headers).

When you upgrade from a version before 1.6.0, add the `realtime` service and the
`BROADCAST_CONNECTION: reverb` line from `compose.yaml` to your own compose file. Without them,
or whenever the connection drops, the pages ask every few seconds as they did before.

While at least one page is open, the scheduler container asks the nodes every two seconds what
their servers are doing, once for everyone.

## Mods

Servers that run Fabric, Forge, NeoForge or a modpack have a **Mods** page. It lists the mods
from [Modrinth](https://modrinth.com) that have a build for the server's loader and Minecraft
version; one click downloads a mod into the server's `mods` folder, together with the mods it
requires. The same page lists what is in that folder, also the jars you uploaded yourself, marks
the ones with a newer build and removes them. A change loads on the next restart.

On CurseForge and FTB packs required mods are named but not added by themselves: such a pack
often ships them under a file Modrinth does not know, and a mod that is there twice keeps the
server from starting. The node needs to reach `cdn.modrinth.com`, the panel `api.modrinth.com`.

## Modpack updates

A modpack server's settings have two choices, both off at first. **Look for new versions** asks
Modrinth, CurseForge or FTB every hour for the pack's newest release; a new one is recorded in the
activity log and reported to webhooks that follow modpack updates. **Install new versions
automatically** also installs it: a running server is backed up first (the backup is named
"Before auto-update") and restarted, and nothing is touched when that backup fails. A server
without a chosen pack version installs the newest one at every start anyway, so the check only
reports versions that come out after it first looked.

## Languages

The panel is written in English, and every text goes through a translation lookup with the
English text as the key. A language is one file, `panel/lang/<code>.json`:

```sh
cd panel
php artisan lang:sync nl   # creates or updates lang/nl.json with every text that has no translation yet
```

The command also writes `panel/lang/<code>/validation.php`, Laravel's own English messages for
rejected forms, to translate the same way; without that file they stay English.

Translate the values in that file and set its `"@language"` entry to the language's own name.
An entry that still holds the English text is simply shown in English. Every file in `lang/`
shows up under Settings, Appearance, where each user picks their language; `APP_LOCALE` sets it
for people who have not chosen. Run the command again after an update to pick up new texts.

## The API

Everything the panel can do, a token can do: `/api/v1`, documented at `/api-docs` and described
by `panel/resources/api/openapi.json`. A token never exceeds its owner — a viewer's token can
only reach the servers shared with them, whatever abilities it holds.

Make a token under Settings, API tokens. Choose a starting point (read-only, operator or full
access) and adjust the groups underneath; the sentence below the form says what the token will
be able to do.

Signing in, changing a password, two-factor, passkeys and making tokens have no API: a stolen
token must not be able to lock you out or mint another one.

### A launcher

A game launcher needs `servers:read` and `servers:join`, both in the read-only starting point. A
server's answer names its Minecraft version, its modpack with the version that is installed, and
its mod loader with its version. `/servers/{server}/client-mods` lists what the player's game
needs on top of that: on a modpack server only the jars added to the pack and the pack's jars
that were taken out, elsewhere every jar. A jar Modrinth does not know is downloaded through the
panel, one file at a time; this needs no files permission on the server, only seeing it.

For a CurseForge server `/servers/{server}/client-pack` says where the pack's zip is. The files
of the pack are named in the `manifest.json` inside it; the launcher posts their numbers to
`/servers/{server}/client-pack/files` and gets back where each is downloaded, so the CurseForge
key never leaves the panel.

### Serving a launcher's releases

The panel can hold the installer of your launcher and its update file. Three addresses need no
login: `/portal/latest.json` (what the launcher's updater reads), `/portal/download` (redirects
to the newest installer, for a first install) and `/portal/spawnpoint-portal-<version>-setup.exe`.
Only the three newest releases are kept, in the `portal` volume. An install that never uploads
anything serves nothing: all three answer 404.

Upload with a token that has the `portal:release` ability, which only an admin can give. The body
is the installer itself, up to 100 MB; the version must be greater than the newest one:

```sh
curl -X PUT https://panel.example.com/api/v1/portal/releases/1.2.3 \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/octet-stream" \
  -H "X-Signature: $(cat setup.exe.sig)" \
  -H "X-Sha256: $(sha256sum setup.exe | cut -d' ' -f1)" \
  --data-binary @setup.exe
```

### Pairing an app

An app never needs your password. It shows an eight-character code; under Settings, API tokens,
type the code, check which device is asking, choose what it may do, and approve. The app then
collects its token once. A code is good for ten minutes.

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
