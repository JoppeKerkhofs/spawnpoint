# Mod manager Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Mods page (and API) per server that installs mods from Modrinth into `mods/` and lists what is installed.

**Architecture:** The node gets two signed endpoints: list jars with SHA-512, and download one file from Modrinth's CDN into `mods/`. The panel's `App\Services\Mods` talks to Modrinth (search, identify by hash, pick the build, dependencies) and tells the node what to fetch. Nothing is stored in the database; the folder is the truth.

**Tech Stack:** Rust (axum, sha2, curl binary), Laravel 13, Inertia v3 + Vue 3, Pest.

**Spec:** `docs/superpowers/specs/2026-11-15-mod-manager-design.md`

## Global Constraints

- Branch `mod-manager`. No attribution lines in commits. No `@` tokens in commit messages.
- Version in `panel/composer.json` becomes `1.6.0`.
- Texts through `t()` / `__()`, literal English keys. Comments short, only for a non-obvious why.
- Mods page exists for `FABRIC`, `FORGE`, `NEOFORGE` and `Server::PACK_TYPES`; 404 otherwise.
- Permission `files`; abilities `files:read` / `files:write`. No new ability.
- The node downloads only from `https://cdn.modrinth.com/`, never follows redirects, checks SHA-512.
- Tests send tokens with `bearer($token)`; replace a `beforeEach` fake with `Http::swap(new Factory)` first.
- Rust tests run in a container: `wsl.exe -e bash -lc 'docker run --rm -v /mnt/c/Users/joppe/github/spawnpoint/service:/src -w /src rust:1-slim-bookworm cargo test --locked'`.
- Before done: `php artisan test --compact`, `vendor/bin/pint --dirty --format agent`, `composer types:check`, `npm run check:fix`, `npm run types:check`, `php artisan wayfinder:generate --with-form`, Rust tests, and the page in a browser.

## Review Focus

- A `url` or `name` from the panel that tries to make the node fetch from elsewhere or write outside `mods/` (redirects, `..`, a name not ending in `.jar`): refused with 422. Test in Task 1.
- Modrinth down: the page still lists the jars by file name; installs answer an error, not a 500. Tests in Tasks 3 and 4.
- A dependency cycle (A needs B, B needs A) or a dependency already installed: each project is fetched at most once. Test in Task 3.
- A pack whose loader cannot be read: installed list works, install refused with a clear message. Test in Task 4.
- A viewer without the `files` permission, and a token with only `files:read`, cannot install or remove. Tests in Tasks 4 and 5.

---

### Task 1: Node endpoints

**Files:**
- Modify: `service/src/files.rs`, `service/src/main.rs` (routes near line 1188, `mods_newer_than` at 828), `contracts/openapi.yaml`

**Interfaces:**
- Produces: `GET /servers/{slug}/mods` → `[{"name","size","sha512"}]` sorted by name, `[]` when `mods/` is missing.
  `POST /servers/{slug}/mods` body `{"url","name","sha512"}` → 204; 422 for a bad url or name; 502 when the download fails or the hash differs.

- [ ] **Step 1: failing tests** in `files.rs` tests:
  - `mod_downloads_are_limited_to_modrinth_jars`: `valid_mod("https://cdn.modrinth.com/data/a/b.jar", "b.jar")` true; false for `http://cdn.modrinth.com/…`, `https://cdn.modrinth.com.evil.example/x.jar`, name `../b.jar`, name `b.zip`, name `a/b.jar`.
  - `jars_are_listed_with_their_fingerprint`: a temp server with `mods/a.jar` containing `abc` and `mods/notes.txt` → `jar_list(root)` is one entry, name `a.jar`, size 3, sha512 `ddaf35a193617abacc417349ae20413112e6fa4e89a97ea20a9eeee64b55d39a2192992a274fc1a836ba3c23a3feebbd454d4423643ce80e2a9ac94fa54ca49f`; a server without `mods/` → empty.
  - in `main.rs` tests `a_removed_mod_counts_as_one_change`: `mods/` exists with no jar and its mtime is after `since` → `mods_newer_than` is 1; with `since` in the future → 0.
- [ ] **Step 2:** run the Rust tests. Expected: compile errors for the missing functions.
- [ ] **Step 3:** implement `fn valid_mod(url: &str, name: &str) -> bool`, `fn jar_list(root: &FsPath) -> Vec<Value>`, `pub async fn list_mods`, `pub async fn install_mod` (create `mods/` and `adopt` it; `curl -fsS --proto =https --max-time 300 -o .{name}.downloading url`, no `-L`; hash; rename; `adopt`; remove the partial on any failure), the folder-mtime rule in `mods_newer_than`, the route `.route("/servers/{slug}/mods", get(files::list_mods).post(files::install_mod))`, and both operations in `contracts/openapi.yaml`.
- [ ] **Step 4:** run the Rust tests. Expected: all pass.
- [ ] **Step 5:** commit "Let the node list and download mods".

### Task 2: Where a server's mods come from

**Files:**
- Modify: `panel/app/Models/Server.php`, `panel/app/Services/NodeClient.php`
- Create: `panel/app/Services/Mods.php`, `panel/tests/Feature/ModTest.php`

**Interfaces:**
- Produces: `Server::MOD_LOADERS = ['FABRIC','FORGE','NEOFORGE']`, `Server::hasMods(): bool`, key `has_mods` in `Server::forUser()`.
  `NodeClient::mods(Server): list<array{name: string, size: int, sha512: string}>`, `NodeClient::installMod(Server, string $url, string $name, string $sha512): void`.
  `Mods::target(Server): ?array{loader: string, version: string}` (loader lower case, as Modrinth names it).

- [ ] **Step 1: failing tests** (`ModTest.php`, dataset or separate tests):
  - Fabric 1.21.10 → `['loader' => 'fabric', 'version' => '1.21.10']`, no HTTP.
  - NeoForge `LATEST` → version is the first of `MinecraftVersions::releases()` (fake Mojang's manifest); null when Mojang is down.
  - `MODRINTH` pack `{project: 'cobblemon-fabric', version: 'abc'}` → asks `api.modrinth.com/v2/project/cobblemon-fabric/version/abc`, answer `{loaders:['fabric'], game_versions:['1.21.1']}`; without a version asks `/v2/project/cobblemon-fabric/version` and takes the first.
  - `AUTO_CURSEFORGE` pack → `api.curseforge.com/v1/mods/search?…slug=all-the-mods-10`, `data.0.latestFilesIndexes.0` = `{gameVersion:'1.21.1', modLoader:6}` → neoforge (1 forge, 4 fabric, 5 quilt, 6 neoforge).
  - `FTBA` pack `{project:'126', version:'100'}` → `api.feed-the-beast.com/v1/modpacks/public/modpack/126/100`, `targets` with `{type:'modloader',name:'neoforge'}` and `{type:'game',version:'1.21.1'}`.
  - catalogue answers 500 → null, and the next call asks again (not cached).
  - `hasMods()` false for `VANILLA`, `PAPER`, `PUMPKIN`.
- [ ] **Step 2:** `php artisan test --compact --filter=ModTest`. Expected: FAIL, class `Mods` not found.
- [ ] **Step 3:** implement. Pack answers are cached a day under a key of server id and pack; null is not cached. A pasted Modrinth link (`https://modrinth.com/modpack/{slug}[/version/{v}]`) is parsed for slug and version.
- [ ] **Step 4:** run the filter. Expected: PASS.
- [ ] **Step 5:** commit "Work out the loader and version a server's mods need".

### Task 3: Catalogue, installed list, install

**Files:**
- Modify: `panel/app/Services/Mods.php`, `panel/tests/Feature/ModTest.php`

**Interfaces:**
- Consumes: Task 2.
- Produces:
  - `Mods::search(array $target, string $query, int $offset): array{mods: list<array{project: string, name: string, description: string, icon: ?string, downloads: int}>, more: bool}` (`project` is Modrinth's project id), 20 per page. Throws the HTTP client exceptions.
  - `Mods::installed(Server $server, ?array $target): list<array{file: string, size: int, project: ?string, name: ?string, icon: ?string, version: ?string, update: bool}>`. Node failures throw; Modrinth failures leave the plain file list.
  - `Mods::install(Server $server, array $target, string $project): array{installed: list<string>, missing: list<array{project: string, name: string}>}`. Throws `ValidationException` on key `project` when no build matches. Installing a project that is already there replaces its jar (this is "update").

- [ ] **Step 1: failing tests:**
  - search sends facets `[["project_type:mod"],["categories:fabric"],["versions:1.21.10"],["server_side:required","server_side:optional"]]`, `limit=20`, the offset, `index=downloads` without a query; `more` is true when `total_hits` exceeds offset + 20.
  - installed: node lists `sodium.jar` and `private.jar`; `POST /v2/version_files` knows the first (project `AANobbMI`, version_number `0.6.13`, id `v1`); `/v2/projects?ids=["AANobbMI"]` gives title and icon; `/v2/version_files/update` returns id `v2` for it → first entry named, `update: true`; second `name: null, update: false`.
  - installed with Modrinth answering 500 → both by file name only.
  - install: project `sodium` → `/v2/project/sodium/version?loaders=["fabric"]&game_versions=["1.21.10"]`; first version has a primary file and a `required` dependency `P7dR8mSH` (not installed), an `optional` one, and a `required` one that is installed. Node receives exactly two `POST …/mods` with url, filename and sha512; result `installed` has both titles, `missing` empty.
  - install with A requiring B and B requiring A → two downloads, no loop.
  - install on an `AUTO_CURSEFORGE` server → one download; `missing` names the required project.
  - install when the version list is `[]` → `ValidationException`, node not called.
  - installing a project whose jar `sodium-old.jar` is installed, new file `sodium-new.jar` → download, then `DELETE …/files?path=mods%2Fsodium-old.jar`.
- [ ] **Step 2:** run the filter. Expected: FAIL, method not found.
- [ ] **Step 3:** implement. Use the User-Agent of `Modpacks::http()` (make that helper reusable rather than copying it).
- [ ] **Step 4:** run the filter. Expected: PASS.
- [ ] **Step 5:** commit "Search, identify and install mods through Modrinth".

### Task 4: Web routes and controller

**Files:**
- Create: `panel/app/Http/Controllers/ModController.php`
- Modify: `panel/routes/web.php` (inside the `files` group), `panel/tests/Feature/ModTest.php`

**Interfaces:**
- Produces routes: `servers.mods` GET `servers/{server}/mods` (Inertia `servers/Mods`, props `server`, `states` deferred, `target`, `installed`, `error`, `missing` from the session), `servers.mods.search` GET `…/mods/search?q=&offset=` (JSON `{mods, more}` or `{message}` with 502), `servers.mods.store` POST `…/mods` `{project}`, `servers.mods.update` PUT `…/mods/{file}`, `servers.mods.destroy` DELETE `…/mods/{file}`; `file` matches `[^/]+\.jar`. `project` matches `/^[A-Za-z0-9_.-]{1,64}$/`.
- Activity: `mod.install` `['mod' => name]`, `mod.update` `['file' => file]`, `mod.remove` `['file' => file]`.

- [ ] **Step 1: failing tests:** page props for a Fabric server; 404 for a Paper server on every route; a viewer without `files` gets 403; store flashes a success toast naming the mod and its dependencies and records `mod.install`; store on a pack whose target is null flashes an error and sends nothing to the node; store with Modrinth down flashes an error (no 500); update of a file Modrinth does not know flashes an error; destroy sends `DELETE …/files?path=mods%2Fsodium.jar` and records `mod.remove`; search returns 502 with a message when Modrinth is down.
- [ ] **Step 2:** run. Expected: FAIL, route not defined.
- [ ] **Step 3:** implement; the 404 rule is controller middleware (`HasMiddleware`) on `Server::hasMods()`. Then `php artisan wayfinder:generate --with-form`.
- [ ] **Step 4:** run. Expected: PASS.
- [ ] **Step 5:** commit "Add the mods routes to the panel".

### Task 5: API

**Files:**
- Create: `panel/app/Http/Controllers/Api/V1/ModController.php`, `panel/tests/Feature/ApiModTest.php`
- Modify: `panel/routes/api.php` (inside the `files` group), `panel/resources/api/openapi.json`

**Interfaces:**
- `GET servers/{server}/mods` (`files:read`) → `{data: installed, target}`; `GET …/mods/search` (`files:read`) → `{data: mods, more}`; `POST …/mods` (`files:write`) → 201 `{message, installed, missing}`; `PUT …/mods/{file}` (`files:write`) → `{message}`; `DELETE …/mods/{file}` (`files:write`) → `{message}`. Unknown target on a write or search → 422 `{message}`; Modrinth or node failure → 502 `{message}`.

- [ ] **Step 1: failing tests:** a `files:read` token lists and searches but gets 403 "This token lacks the files:write ability." on POST, PUT and DELETE; a `files:write` token installs (201, shape above) and removes; a Paper server is 404; the existing documentation test (`AdminTest`) fails until `openapi.json` has the five operations with `x-ability`.
- [ ] **Step 2:** run `--filter=ApiModTest`. Expected: FAIL, 404.
- [ ] **Step 3:** implement and document.
- [ ] **Step 4:** `php artisan test --compact`. Expected: all pass.
- [ ] **Step 5:** commit "Offer the mod manager over the API".

### Task 6: The page

**Files:**
- Create: `panel/resources/js/pages/servers/Mods.vue`
- Modify: `panel/resources/js/components/ServerHeader.vue` (tab `mods`, shown when `server.has_mods` and the user has `files`), `panel/resources/js/lib/servers.ts` (`has_mods`), the language files via `php artisan lang:sync <code>` for each existing language.

- [ ] **Step 1:** build the page as sketched in the spec: restart notice (same as Files), installed list (icon, name or file name, version, "Update available" + Update, Remove with `ask()`), missing-dependency list with Install buttons, catalogue with debounced search, "Installed" instead of the button for projects already there, Load more. One install at a time; the button shows progress. When `target` is null the catalogue is replaced by the explanation.
- [ ] **Step 2:** `npm run check:fix`, `npm run types:check`, `npm run build`, `php artisan test --compact`. Expected: clean.
- [ ] **Step 3:** browser check against the dev stack with a real Fabric server: search, install a mod with a dependency (e.g. Sodium or one needing Fabric API), see both jars in Files, update marker, remove, restart notice; keyboard reachability and the dark theme.
- [ ] **Step 4:** commit "Add the Mods page".

### Task 7: Release

**Files:**
- Modify: `panel/composer.json` (1.6.0), `README.md`, `CLAUDE.md`, the spec's status line.

- [ ] **Step 1:** version, a README paragraph on the mod manager, a CLAUDE.md note (mods are never recorded in the database; the node only downloads from Modrinth's CDN).
- [ ] **Step 2:** run every check in Global Constraints. Expected: all green.
- [ ] **Step 3:** commit "Release 1.6.0".
