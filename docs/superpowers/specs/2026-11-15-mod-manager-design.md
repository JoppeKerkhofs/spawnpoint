# Mod manager (1.6.0)

Status: built in 1.6.0.

## Intent

Adding a mod today means finding the right jar for the loader and Minecraft version yourself and
uploading it on the Files page. The owner wants a page that works like the ATLauncher mod picker:
browse the mods that fit this server, click one, and it is downloaded into the server. The same
page shows what is already installed.

## Goals

- A **Mods** page on every server whose type is `FABRIC`, `FORGE` or `NEOFORGE`, and on modpack
  servers (`Server::PACK_TYPES`), where mods are added on top of the pack.
- A catalogue from the Modrinth API, limited to mods that have a build for the server's loader and
  Minecraft version and that run on a server. Searchable; without a search term the most
  downloaded come first; more can be loaded.
- Installing a mod downloads the newest matching build into the server's `mods/` folder, together
  with its required dependencies that are not installed yet.
- A list of the installed mods, read from the real `mods/` folder. Jars Modrinth recognises show
  with name, icon and version; the rest show by file name. Jars uploaded by hand are in this list
  like any other.
- Per installed mod: remove, and an "update available" marker with an update button.
- The existing "restart to load" notice (`mods_changed`) appears on this page as on Files.
- The same over `/api/v1`, documented in `openapi.json`.

## Non-goals

- Paper plugins, Pumpkin and Vanilla: the page and its routes do not exist for them (404).
- CurseForge or any second catalogue.
- Choosing an older build of a mod, bulk "update all", automatic updates, config editing.
- Removing dependencies that nothing needs any more.

## Anti-goals

- The panel must not keep its own record of installed mods. The folder is the truth; a database
  list would drift the moment someone uploads or deletes a jar on the Files page.
- The node must never download from an address the panel's user could choose freely.
- Nothing may restart a server by itself. Mods load on the next restart, which the user starts.

## Constraints

- The panel never touches files; the node does (`service/`). The node has `curl` (used by
  `backups::fetch`).
- Modrinth asks for a descriptive User-Agent; reuse the one in `Modpacks`.
- `mc_version` may be `LATEST`; it must be resolved to a real version (see `MinecraftVersions`)
  before asking Modrinth.
- All texts through `t()` / `__()`; version in `panel/composer.json` becomes `1.6.0`; nodes are
  kept on the panel's version, so new node endpoints are fine.
- Project rules in `CLAUDE.md` apply (checks, browser verification, short comments).

## Approaches

**A. Image-managed list.** Save Modrinth project names on the server and pass them to the
container as `MODRINTH_PROJECTS`; the image downloads them on start. Least code, but nothing
installs until a restart, the "installed" list is only what was asked for (hand-uploaded jars are
invisible), mods update themselves on every restart, and one mod without a matching build can
stop the server from starting.

**B. The panel installs the jar (chosen).** The panel asks Modrinth for the matching build and
the node downloads that file into `mods/`. The installed list is the folder itself, identified by
file fingerprint. Versions stay until the user updates. Costs two node endpoints and the
dependency lookup.

**C. Both.** Dropped: two sources of truth.

The owner picked B: it is what the ATLauncher picker does and gives a true installed list.

## Design

### Node (`service/`)

Two signed endpoints, beside the other file routes:

- `GET /servers/{slug}/mods` → `[{ "name": "sodium.jar", "size": 123, "sha512": "…" }]` for every
  `*.jar` directly in `mods/`. A missing folder is an empty list.
- `POST /servers/{slug}/mods` with `{ "url", "name", "sha512" }`: download `url` to
  `mods/{name}`, creating the folder if needed.
  - `url` must start with `https://cdn.modrinth.com/`; anything else is 422.
  - `name` must be a plain file name ending in `.jar` (no separators, no `..`).
  - Download to a temporary name, check the SHA-512, then rename over the target. A wrong hash
    or failed download leaves nothing behind and answers 502.
  - Files are owned as the other server files are (uid 1000).

Removing a jar uses the existing `DELETE /servers/{slug}/files`. `mods_changed` (`mods_newer_than`) counts jars
newer than the server's start, so installs and updates raise the restart notice as they are. A
removal leaves no newer jar: when the `mods/` folder itself changed after the start and no jar
counts, report 1, so a removal raises the notice too (my call). Add both endpoints to
`contracts/openapi.yaml`.

### Panel

- `App\Services\Mods` (Modrinth only, beside `Modpacks`):
  - `search(Server, string $query, int $offset)`: `/v2/search` with facets
    `project_type:mod`, `categories:{loader}`, `versions:{mc}`, and server side required or
    optional. 20 per page.
  - `installed(Server)`: the node's jar list, enriched through `POST /v2/version_files`
    (SHA-512) for project id, title, icon and version number, and through
    `POST /v2/version_files/update` (loader + version) to mark which have a newer build.
  - `install(Server, string $project)`: newest build for loader and version
    (`/v2/project/{id}/version?loaders=…&game_versions=…`), its primary file, then the same for
    each `required` dependency whose project is not installed yet, recursively, each project at
    most once. A mod with no matching build is a 422 with a clear message.
  - `update(Server, string $file)`: install the newer build, then delete the old jar if its file
    name differs.
- **Loader and version of a server** (`Mods::target(Server)` → `{loader, version}` or null):
  - `FABRIC`, `FORGE`, `NEOFORGE`: the server type and `mc_version`.
  - Modpack servers: the panel does not store these, so they are read from the pack's own
    catalogue for the chosen pack version (the newest when none is chosen): Modrinth's version
    (`loaders`, `game_versions`), CurseForge's file (`gameVersions`, with the saved key), FTB's
    version (`targets`). Cached per server and pack version.
  - Null (catalogue unreachable, pack unknown): the installed list still works, the catalogue
    part says the pack's loader could not be determined, installs are refused.
- **Dependencies on CurseForge and FTB packs** (owner's decision): jars there are often not
  known to Modrinth, so an automatic dependency could duplicate one the pack ships, and Forge
  and NeoForge refuse to start with a mod twice. On `AUTO_CURSEFORGE` and `FTBA` servers
  `install` adds only the mod itself and returns the required projects it could not find among
  the installed ones; the page lists them, each with its own Install button. Loader servers and
  `MODRINTH` packs install required dependencies automatically.
- On a pack server the installed list shows every jar, the pack's own included. Removing or
  updating a jar the pack installed is allowed; the pack may put it back when it reinstalls.
- `NodeClient`: `mods()`, `installMod()`.
- Web: `ModController` with `index` (page), `search` (JSON for the picker), `store`, `update`,
  `destroy`, under `can:act,server,"files"`. A link in `ServerHeader` on every server that has the
  page: the three loader types and the pack types.
- API: `GET /servers/{server}/mods` (`files:read`), `GET /servers/{server}/mods/search`
  (`files:read`), `POST /servers/{server}/mods` (`files:write`),
  `PUT /servers/{server}/mods/{file}` (update, `files:write`),
  `DELETE /servers/{server}/mods/{file}` (`files:write`).
- Activity: `mod.install`, `mod.update`, `mod.remove`, with the server as subject.
- Modrinth unreachable: the installed list still shows, by file name; the catalogue shows an
  error text; installs answer 502.

### The page

```
Mods — survival (Fabric 1.21.10)              [ restart to load 2 changed mods ]

Installed (3)
  [icon] Sodium            0.6.13    update available  [Update] [Remove]
  [icon] Fabric API        0.138.0                              [Remove]
  [jar]  my-private-mod.jar                                     [Remove]

Add mods                                       [ search…            ]
  [icon] Lithium     No-compromises game logic optimisation   12M   [Install]
  [icon] Sodium      …                                        40M   Installed
  …                                                       [Load more]
```

Installing shows progress on the button, then the installed list reloads and names the
dependencies that were added with it.

## My calls (not the owner's; correct if wrong)

- **No new permission or token ability.** Installing a mod writes a file, so the page uses the
  server's `files` permission and the API uses `files:read` / `files:write`.
- **The node verifies the SHA-512** the panel got from Modrinth.
- **Only required dependencies** are installed; optional ones are not.
- **Update keeps one jar per mod**: the old file is removed after the new one is in place.
- **Catalogue page size 20, "load more"** rather than numbered pages.
- **No caching** of Modrinth answers in the first version, except a pack's loader and version.
- **Pack servers get their loader from the pack's catalogue**, not from guessing at the files.
- **Pack jars are not marked or protected** in the installed list: the panel cannot tell them
  from added ones without keeping its own record.

## To verify on a real server before relying on it

- That each pack installer (Modrinth, CurseForge, FTB) leaves added jars in `mods/` alone when
  the container is recreated or the pack is updated. If one wipes the folder, that pack type
  shows a warning on the page.

## Left to the builder

- How `LATEST` is resolved with what `MinecraftVersions` already offers.
- Whether the picker reuses parts of `ModpackPicker.vue`.
- Exact JSON shapes of the API responses, following the existing controllers.
- Hashing in the node: `sha2` if it is already a dependency, otherwise the smallest addition.
