# Spawnpoint

A self-hosted panel that creates and manages Minecraft Java servers, each in its own Docker
container. This file explains how it fits together and what is easy to get wrong.

## Layout

| Path | What it is |
| --- | --- |
| `panel/` | The panel: Laravel 13, Inertia v3, Vue 3, TypeScript, Tailwind v4, Fortify, Sanctum, Pest |
| `service/` | The node: a Rust service (Axum, bollard, tokio) that does all Docker and file work |
| `docker/` | `Caddyfile` and `entrypoint.sh` of the panel image |
| `Dockerfile` | The panel image (FrankenPHP). `service/Dockerfile` is the node image |
| `compose.yaml` | Deployment, can build from the checkout |
| `compose.prod.yaml` | Deployment from published images only, hardened |
| `compose.node.yaml` | An extra host that only runs a node |
| `compose.dev.yaml` | Node, router and map proxy for development; the panel runs on the host |
| `contracts/openapi.yaml` | The node's HTTP API |
| `panel/resources/api/openapi.json` | The panel's public API (`/api/v1`), also rendered at `/api-docs` |

`panel/AGENTS.md` holds the Laravel conventions for the panel; follow it for work under `panel/`.

## How it works

**Panel and nodes.** The panel never touches Docker. A node is a host that runs Minecraft
servers; the panel sends it signed HTTP requests. There is always a `local` node, registered by
`panel:setup` from the environment on every start; more are added on the Nodes page.

**Signing.** Every panel-to-node request carries `X-Timestamp` and `X-Signature`:
HMAC-SHA256 with the node's token over `"{timestamp}\n{METHOD}\n{path_and_query}\n{body}"`,
60 seconds of clock skew allowed. `NodeClient::query()` builds the query string exactly as it is
signed. Over http the traffic is signed but not encrypted, so such nodes belong on a private
network. With `SPAWNPOINT_TLS_CERT` and `SPAWNPOINT_TLS_KEY` the node serves the same port over
TLS (`axum-server`) and its address in the panel becomes `https://`; `Node::upstream()` then
keeps the scheme, so the browser relay uses TLS too. The panel must trust the certificate.

**Browser tokens.** The console WebSocket and file transfers go from the browser to the node
with a short-lived token: `hex(json{slug,exp,write}) + "." + hex(hmac(purpose + "\n" + payload))`,
purpose `console` (60 s) or `files` (300 s). `NodeClient::token()` mints it, `claims()` in
`service/src/main.rs` checks it.

**The relay.** Browsers never reach a node directly. They use `/node/<node id>/servers/...`,
and Caddy asks the panel (`/node-upstream/{node}`, loopback only) where that node is, then
proxies there. Only the token-authorised paths are relayed; a node's signed API is not.

**Servers.** A server is identified by its `slug`, the first label of its hostname. The container
is `mc-{slug}`, its data is `{SPAWNPOINT_DATA_DIR}/{slug}`, the image is `itzg/minecraft-server`.
State is read live from Docker; there is no status column. Long tasks (backup, restore, import)
are tracked in memory in the node.

**Backup folders.** A node keeps archives in `SPAWNPOINT_BACKUP_DIR/{slug}`. With
`SPAWNPOINT_BACKUP_FALLBACK_DIR` set it checks every 15 seconds that the first can be written to
and is not on the fallback's disk (an unmounted share), and otherwise writes to the fallback and
never touches the first. The list reads both and marks those in the fallback (`fallback`). The
node says so when a backup starts, `NodeClient::createBackup()` records `backup.fallback`, which
webhooks following backups get. `/host` reports the folders for the Settings page.

**Moving a server.** `MoveServer` (a queued job) stops the server, backs it up on its node,
`apply`s it on the new node and posts `/servers/{slug}/fetch` there with a backup download URL
signed with the old node's token; the new node pulls the archive with `curl` and restores it.
Only then does `node_id` change and the old container go. The old data folder stays behind.

**Players.** `mc-router` listens on 25565 on each node's host and forwards by hostname, using
the `mc-router.host` label. Web maps go through Traefik on 8100 at `/<slug>/`.

**Nodes without an address.** While a node's `dns_target` is empty its servers are reached
through the panel's host: `App\Services\Proxy` writes `minecraft.json` (for `mc-router`) and
`maps/maps.yml` (for Traefik) into `PANEL_PROXY_DIR`, a volume both proxies watch, and they
forward to the node's host on its `minecraft_port` and `maps_port` (25565 and 8100 when
empty). An empty `bluemap_url` does the same for maps
alone. The files are written again whenever a server or node changes in a way that matters
(the models' `booted()`), and at every start. `mc-router` never drops a route it read from the
file until it restarts, drops any route when a container with that hostname goes, and forgets
the labelled default server each time it reads the file, which is why the file names it.

**Access.** Roles are `admin` and `viewer`. A viewer sees only shared servers; the share lists
what they may do there (`Server::PERMISSIONS`). Routes check it with
`can:act,server,"files"`, and the quotes matter: without them Laravel takes the word for a route
parameter. API tokens need both the token ability and the owner's permission.
Abilities live in `App\Api\Abilities` and are checked by the `ability:` middleware beside
`can:`; `backups:manage` is a retired name kept for tokens issued before 1.5.0. A device is
paired by code (`device_pairings`), never by handing over a password.

**Mods.** The Mods page (Fabric, Forge, NeoForge and modpack servers) keeps no record: the
node lists the jars in `mods/` with their SHA-512, and Modrinth names them by that fingerprint
(`App\Services\Mods`). To install, the panel picks the build and the node downloads it; the node
only fetches from `https://cdn.modrinth.com/`, without redirects, and checks the hash.

**Modpack updates.** `modpacks:check` (hourly) asks the catalogue for the newest release of each
pack server with `pack_check` and remembers it in `pack_latest`, so a version is reported once
(`modpack.outdated`). With `pack_auto_update` it queues `UpdateModpack` instead: a running server
is backed up first, then a chosen version is replaced and the container recreated, and a server
without one is restarted, because the Minecraft image installs the newest as it starts.

**What a server runs.** Not stored: the Minecraft image records what it installed in the
server's folder (`.modrinth-modpack-manifest.json`, `.curseforge-manifest.json`, FTB's
`.manifest.json`, `.fabric-manifest.json`, `.forge-manifest.json`, `.neoforge-manifest.json`),
and `Mods::runs()` reads that through the node's download address, cached for a minute. The API
answers for a launcher (`servers:join`) come from it, as does the pack's own file list that
`client-mods` compares the mods folder with.

**Live updates.** `App\Events\Changed` (a server's slug or null, and what: `state` or the
first part of an activity event) is broadcast through Reverb to one private channel per user;
who gets it is decided when it is sent. It never carries data: pages answer with a partial
reload (`resources/js/lib/live.ts`), and poll while the socket is down. `ActivityLog::record()`
sends it for everything a person does; `servers:broadcast` (started every minute, looks every
two seconds, only while a page is connected) sends `state` and caches what it fetched for
`NodeClient::recentStates()`. Reverb's credentials are derived from `APP_KEY`.

**Pictures.** A server's picture is `servers.icon` (a 64x64 PNG as base64, hidden; pages get
`picture`) and the same bytes as `server-icon.png` in its folder, written through the node's
file API without recreating the container. A modpack server without one gets the pack's picture
address as `ICON`, which the Minecraft image downloads only while no `server-icon.png` exists.
The image stops a server whose `ICON` cannot be fetched, so the panel first checks it answers,
and only takes addresses on the catalogues' picture hosts (`Server::PICTURE_PREFIXES`, the same
list as in the node): the address is chosen by whoever configures the server.

**Launcher releases.** `PUT /api/v1/portal/releases/{version}` (`portal:release`, admin) streams an
installer into `portal/` on the `local` disk (a volume) with a JSON file beside it. Every release is
kept until someone removes it on `/portal/versions`: a public page that lists them, with buttons
for whoever passes the `manage-launcher` gate (admins, and viewers with
`users.manages_launcher`). A release with `downloadable: false` in its JSON stays listed, is not
served, and is skipped by the updater. `/portal/latest.json`, `/portal/download` and the installer are public routes
without the `web` middleware, so no session, cookie or CSRF. `App\Services\PortalReleases` builds
every path from a version that matched `\d+\.\d+\.\d+`, and URLs from `APP_URL`, never `Host`.

**Panel containers.** `app` (web), `queue` (webhooks, mail), `scheduler` (`schedules:run`,
`servers:watch` and `servers:broadcast` every minute) and `realtime` (`reverb:start`, reached
through the app's Caddy at `/app/*`) are the same image. Only `app` runs migrations.

## Working on it

In `panel/`:

```sh
composer setup                         # dependencies, .env, migrations, assets
composer dev                           # the panel with Vite
php artisan test --compact             # Pest
vendor/bin/pint --dirty --format agent # format PHP
composer types:check                   # PHPStan
npm run check:fix                      # format and lint the frontend
npm run types:check                    # vue-tsc
php artisan wayfinder:generate --with-form   # after changing routes
```

In `service/`: `cargo test --locked`. The dev stack is
`docker compose -f compose.dev.yaml up -d --build`.

Run all of these before saying something is done. A change to a page is not verified until it
was looked at in a browser against a real server.

## Conventions

- **Version:** `panel/composer.json` `version`. Every merge publishes both images under it and as
  `latest`, so a pull request must raise it (`scripts/check-version.sh`). `compose.prod.yaml` and
  `compose.node.yaml` name the same version as their default image tag; a test fails when they
  differ, so raise all three together. Keep nodes on the
  panel's version.
- **Settings:** `Setting::value()` reads the database first, then `config('panel.*')`. Secrets are
  stored encrypted and never sent to the browser; pages get a "has a value" flag instead.
- **Activity:** `ActivityLog::record()` for anything a person does. It also feeds webhooks. It
  reads `request()->user()`, so a job that records something sets the request's user resolver.
  `activity:prune` deletes entries past `activity_retention_days` every night.
- **Texts:** every interface text goes through `t()` (`resources/js/lib/i18n.ts`), with the English
  text as the key and `:name` placeholders. Backend texts use `__()`. Pass `ask()` and `askName()`
  the English text; they translate it. `php artisan lang:sync <code>` collects the keys, so a
  text must be a literal in the call, not a variable. `lang:sync` also collects the texts of
  `openapi.json` (translated when the page is served) and copies Laravel's `validation.php`.
- **Themes:** `PANEL_THEME` sets `data-theme` on `<html>`. The blocky theme is CSS in
  `resources/css/app.css` under `html[data-theme='blocky']`, plus a few components that check
  `page.props.theme`. It has no dark variant and never gets the `dark` class.
- **Naming:** settings are `SPAWNPOINT_*` (node) and `PANEL_*` (panel). The Docker label
  `mcpanel.managed` and the `.mcpanel-stopped` folder keep the old prefix on purpose: renaming
  them would orphan existing servers.
- **Comments:** short, and only for a non-obvious why.

## Things that are easy to get wrong

- **Form numbers arrive as strings.** The node rejects them; cast on the model. Tests send strings.
- **`TrimStrings` would strip file contents**, so `content` is excluded in `bootstrap/app.php`.
- **`Http::fake()`: the first registered stub wins.** To replace a fake set in `beforeEach`, call
  `Http::swap(new HttpFactory)` first.
- **The sanctum guard keeps the first token of a test.** A second request with another token
  is answered as the first. Send tokens with `bearer($token)` (`tests/Pest.php`), which forgets
  the guards. `Sanctum::actingAs` tokens are mocks: ask `tokenCan()`, never read `->abilities`.
- **Tests need built assets** (`npm run build`), or Inertia pages fail on the Vite manifest.
  Server-side rendering is off in tests so a running dev server is not called.
- **Error pages and unmatched routes:** a 404 for an address without a route never runs the web
  middleware, so it has no shared props. `Error.vue` gets theme, name and translations as props.
- **`t()` at module top level fails:** it runs before the page exists. Call it in templates, in
  a component's setup or in functions. Page titles in `defineOptions` stay English and are
  translated where the layout prints them.
- **vue-tsc and `//` in an attribute:** a static or bound attribute value containing `//` (a URL
  placeholder) breaks the type check of the slot variables after it. Put the string in a script
  constant.
- **Caddy reorders directives.** Inside the relay the steps are wrapped in `route { }` so they run
  as written.
- **PHPStan:** no `->pivot` property access and no `withSum` attributes; query instead. `__()`
  returns `array|string`, so cast when a string is required.
- **Console commands** go to the server's own console through `mc-send-to-console` as user 1000;
  RCON is only the fallback and is used for the tick time, once a minute, because every RCON
  connection leaves two lines in the server log.
- **Modrinth and the node fail alike**: both raise the HTTP client's exceptions. `Mods` wraps
  Modrinth's in `ModrinthUnavailable` so a message can say which of the two it was.
- **A page that needs fresh data after someone else's action** listens with `useLiveReload`;
  a new kind of action is announced by recording it in the activity log, nothing else.
- **Tests run with the `null` broadcaster**, where no channel is registered and `live` is null.
- **Player lists** come from `mc-monitor status`, which reports at most about a dozen names.
- **Images are pulled only when missing.** A new server type may need a manual `docker pull`.
- **A node on the same Docker daemon as another sees its containers too**; only use that for tests.

## Not built yet

- A move between nodes goes through a full backup on disk on both sides; nothing streams.
- Translations of the mails and of the texts in `contracts/openapi.yaml`.
