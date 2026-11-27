# Status

Where the security hardening stands, so that work can be picked up from here. Last updated 2026-11-27.

## Done

- **1.9.9** (merged, deployed): uploads no longer write through a planted link, the password reset
  link comes from `APP_URL`, the Backups page gets an `import` token instead of a files token, the
  node container is read-only with `no-new-privileges`, and the panel sends `X-Frame-Options`,
  `X-Content-Type-Options` and `Referrer-Policy`. The three headers were confirmed on the live panel.
- **Both repositories are public**, so Actions minutes are no longer metered. Pull requests from
  forks need approval before their workflows run.
- **Launcher 0.5.0** is released for Windows and Linux.

## Open: pull request 19, version 1.9.10

Branch `feature/JKER_1.9.10_hardening`. All checks pass; not merged yet.

- A world import stops when the disk has less than 2 GB left (`service/src/backups.rs`).
- A webhook is sent to the address that was checked, with no second lookup, and the check uses the
  global range (`Webhook::safeAddress`, `SendWebhook`).
- Requests to a node carry `X-Nonce` inside the signature and a node accepts a signature once
  (`NodeClient::send`, `first_use` in `service/src/main.rs`).
- Only a WebSocket upgrade under `/app/` goes to the realtime container (`docker/Caddyfile`).

**When deploying:** update the panel and every node together. A 1.9.10 panel and a 1.9.9 node
refuse each other, in both directions.

**Still to check after deploying:**

- Live updates on the dashboard still arrive (the WebSocket through the new matcher), and an
  address such as `/app/x` answers 404.
- The import stop on a nearly full disk was never tried end to end; only reading `df` has a test.
- A world upload from the Backups page in a browser (left over from 1.9.9).

## To do by hand

- Check in the package settings on GitHub whether the published images are public or private.

## Left for later

- Pin the Docker actions in `.github/workflows/ci.yml` by commit instead of by tag.
- The launcher's macOS release waits for Apple signing secrets. Setting the repository variable
  `MACOS_RELEASE_RUNNER` in the launcher's repository turns it on.

## Decided against

- A confirmation step on the pairing link: every user of the panel is trusted.
- Moving the panel address or the upload endpoint of the launcher's release into a secret.

## Running the checks on this machine

- Panel, from `panel/`, with PHP 8.5 (`php85`, in PowerShell): `php85 artisan test --compact`,
  `php85 vendor/bin/phpstan analyse --no-progress --memory-limit=2G`,
  `php85 vendor/bin/pint --dirty --format agent`.
- Node, in WSL from `service/`: `CARGO_TARGET_DIR=$HOME/.cache/spawnpoint-node-target cargo test --locked`.
- Most files have CRLF line endings; keep them when editing with a script.
