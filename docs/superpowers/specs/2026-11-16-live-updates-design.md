# Live updates over WebSockets

Status: design, not built. Ships with 1.6.0 (not yet published), so the version stays 1.6.0.

## Intent

Pages should show what changed without the browser asking again and again. Today the dashboard
and every server page poll the live state every 4 seconds per open tab, each poll making the
panel call every node; lists such as backups are only as fresh as the last reload. The owner
wants the backend to push changes to the web application over WebSockets.

## Goals

- One WebSocket connection per browser tab, to the panel's own address.
- Live, without polling while connected:
  - server state (dashboard, server header, everything that reads `states`);
  - the Backups page: list and running task;
  - the Mods and Files pages when their contents change through the panel;
  - the Activity page.
- A change made through the panel reaches other open tabs at once. A change that happens on a
  node by itself (a backup finishing, a crash, a server done starting) within about 2 seconds.
- If the connection is not there, pages poll as they do today. Nothing gets worse.
- Nobody receives events for a server they may not see, also not after a share is taken away
  while their tab is open.

## Non-goals

- Nodes pushing events to the panel. (Later, behind the same browser channel.)
- Live updates for the mobile app or API tokens: channel access uses the web session.
- The console: it keeps its own connection to the node.
- Live lists of users, nodes, settings, tokens.
- More than one Reverb instance.
- Announcing uploads. Files and world zips go from the browser straight to the node, so the
  panel does not know when one lands; the tab that uploads reloads itself as it does today,
  other tabs see it on their next reload.

## Anti-goals

- No second data path: an event never carries page data that the page also gets as a prop.
  Events say "this changed"; the page reloads the props concerned through Inertia, so every
  permission check and every shape stays where it is.
- The nodes must not be asked every 2 seconds, day and night, when no one has the panel open.
- No new settings an operator has to invent.

## Approaches

**Browser channel.** *A. Laravel Reverb (chosen):* Laravel's WebSocket server as one more
container of the panel image, private channels authorised by the existing permissions.
*B. Straight to each node, as the console does:* no container, but a connection per node,
permissions rebuilt in Rust, and nothing for changes only the panel knows. Rejected.

**Learning of changes.** *1. A watcher in the panel (chosen):* one loop asks the nodes every
2 seconds and broadcasts differences; panel actions broadcast at once. No node changes.
*2. Nodes push to the panel:* instant, but a new network direction for remote nodes, with new
settings and authentication. Left for later.

The owner picked A and 1.

## Design

### Server

- **Reverb** (`laravel/reverb`), started as `php artisan reverb:start` on port 8081 in a new
  container `realtime` (same image, `RUN_MIGRATIONS=false`), in `compose.yaml` and
  `compose.prod.yaml`. The panel publishes to it at `http://realtime:8081`.
- **Same address for the browser.** The app's Caddy passes `/app/*` (the WebSocket path) on to
  `{$REVERB_UPSTREAM:realtime:8081}`. The browser connects to the panel's own host and scheme,
  so a TLS proxy in front needs nothing new beyond passing WebSocket upgrades.
- **No new secrets.** `config/reverb.php` and `config/broadcasting.php` derive the app id, key
  and secret from `APP_KEY` (HMAC with fixed labels). The key is given to the browser as a
  shared Inertia prop together with what the client needs to connect.
- **One channel per user:** `users.{id}` (private), authorised for that user only
  (`routes/channels.php`). A tab makes one authorisation request, however many servers it shows.
- **One event,** `Changed`, `ShouldBroadcastNow`, with `server` (a slug, or null) and `what`:
  `state` from the watcher, or the first part of an activity event (`backup`, `mod`, `file`,
  `server`, `schedule`, `world`, `dns`, `user`, `node`, `settings`, `token`). No page data.
- **Who gets it is decided when it is sent:** an event about a server goes to the channels of
  the admins and of the viewers that server is shared with at that moment; one without a
  server goes to the admins. So a withdrawn share stops the events at once.
- **One hook for panel actions:** `ActivityLog::record()` broadcasts `Changed`. Everything a
  person does already goes through it.
- **The watcher:** `servers:broadcast`, started every minute by the existing scheduler
  container, in the background and never twice at once; it loops for that minute with 2
  seconds between runs, so PHP starts once a minute, not every 2 seconds.
  - Each run first asks Reverb whether any channel is occupied; with none, or with Reverb not
    answering, it does nothing.
  - Otherwise it calls `NodeClient::states()`, stores the result in the cache for 6 seconds
    (`server-states`), compares each server's entry with the previous one and broadcasts
    `Changed` with `state` for those that differ. A changed `last_backup` or finished task is a
    state change, so the Backups page follows backups that end by themselves.
  - Fast-moving numbers (CPU, memory, tick time, player counts) are part of the comparison only
    every fifth run, so a running server causes an event about every 10 seconds, not every 2.
- **Cheap reloads:** where pages get `states`, the controller uses the watcher's cached copy
  when it is fresh and asks the nodes itself otherwise. A reload caused by an event then costs
  no node call.
- **Failure:** a broadcast that cannot reach Reverb is logged once a minute at most and never
  fails the request or the action that caused it.

### Browser

- `laravel-echo` and `pusher-js`; one Echo instance per tab (`resources/js/lib/live.ts`).
- `useLive(handler)` listens on the user's channel for the life of a component.
- **States:** `ServerHeader` and `Dashboard` reload `only: ['states']` on `state` (the header
  only for its own server). They keep `usePoll(4000)` running only while the socket is not
  connected. The dashboard reloads its server list on `server`.
- **Pages,** each for its own server: Backups reloads its list on `backup`, `world`,
  `schedule` and `state`; Mods on `mod` only (its list is costly: every jar is fingerprinted);
  Files on `file` or `mod` (the folder shown, not an open editor); Activity on anything, first
  page only.
- A page never reloads in answer to an event while it has a form submission of its own in
  flight; its own response already carries the new data.

### Development and tests

- `composer dev` also runs `reverb:start`; `.env.example` gets what a local setup needs, the
  browser then connects to `localhost:8081` directly.
- Tests run with the `null` broadcaster; events are asserted with `Event::fake`.

## My calls (not the owner's; correct if wrong)

- **Events carry no data, pages reload.** Slightly more requests than pushing payloads, far
  less code and no second place where permissions matter.
- **Keys derived from `APP_KEY`.** Rotating `APP_KEY` drops live connections once.
- **The watcher sleeps when no one is connected**, and usage numbers refresh about every 10
  seconds instead of 4.
- **The watcher lives in the scheduler container**, not in a container of its own.
- **One channel per user instead of one per server**, with the recipients worked out at send
  time. That is what makes a withdrawn share take effect immediately.
- **"About 2 seconds" holds while the nodes answer.** They are asked one after another, so a
  node that is down delays that round by its 2-second connection timeout.
- **Uploads are not announced to other tabs** (see non-goals).
- **The Mods page does not reload when a file changes elsewhere on the server.**
- **Version stays 1.6.0**, published together with the mod manager.
- **Operators must add the `realtime` container** when they upgrade. Without it the panel works
  as before, by polling.

## To verify on a real setup

- That a reverse proxy in front (the README's examples) passes the upgrade on `/app/*`.
- The path prefix Reverb listens on (`/app`) does not collide with a panel route.

## Left to the builder

- Reverb's exact configuration keys, and how Echo is told to use the page's own host and port.
- Where the "connected" state lives so that polling and events never run together.
- The comparison that leaves fast-moving numbers out.
- README: the new container, and the proxy note.
