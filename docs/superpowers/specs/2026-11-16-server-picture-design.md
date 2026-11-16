# Server picture (1.6.2)

Status: built in 1.6.2.

## Intent

Each server gets its own picture: uploaded by its admin, shown to players in Minecraft's server
list and in the panel in place of the rotating block. A modpack server shows the pack's picture
until one is uploaded.

## How

- **Minecraft** reads `server-icon.png` (PNG, exactly 64×64) from the server's folder at start,
  so a new picture reaches players after the next restart.
- **Upload:** a Picture section on the Configuration page. The browser scales and centre-crops
  the chosen image to 64×64 PNG on a canvas and sends that as a data URL. The panel accepts only
  a real 64×64 PNG (`getimagesizefromstring`), keeps it in `servers.icon` (base64) and has the
  node write `server-icon.png`. No container is recreated, nothing restarts.
- **Modpack default:** the container gets `ICON=<the pack picture's address>`. The Minecraft
  image downloads and converts it at start, but only while there is no `server-icon.png`, so an
  uploaded picture always wins. The image stops the server when that download fails, so the
  panel passes the address only after checking that it answers.
- **Remove:** clears `servers.icon` and deletes `server-icon.png`; on a pack server the next
  start fetches the pack's picture again.
- **Changing the pack** of a server without an uploaded picture deletes `server-icon.png`, so
  the new pack's picture is fetched.
- **In the panel:** `picture` in a server's data is the uploaded picture as a data URL, else the
  pack's picture address, else null. The dashboard and the server header show it; without one
  the blocky theme keeps its rotating block and the default theme shows nothing, as today.
- **Routes:** `PUT` and `DELETE servers/{server}/icon`, web under the `configure` permission,
  API with `servers:configure` as well. Activity `server.icon`.
- **Node:** `ServerConfig` gains `icon` (an https address, plain characters). The panel sends
  the key only when it has one, so servers without it keep working with an older node.

## Approaches

- *Resize in the panel:* needs the GD extension in the image for this one feature. Rejected.
- *Keep pictures only on the node:* every list view would ask the nodes for them. Rejected.
- *Browser resizes, the Minecraft image converts pack pictures (chosen).*

## My calls

- Shown in both themes.
- Existing modpack servers get the pack picture the next time their configuration is saved.
- The API takes the same 64×64 PNG data URL; resizing is the client's job.

## Not verified without a running Minecraft server

- That the image's `ICON` handling behaves as its documentation and start script say.
