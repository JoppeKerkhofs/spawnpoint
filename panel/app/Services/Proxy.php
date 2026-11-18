<?php

namespace App\Services;

use App\Models\Node;
use App\Models\Server;
use Illuminate\Support\Facades\File;
use stdClass;

/**
 * Tells the router and the map proxy on the panel's host which servers live on another node, so
 * that a node without a public address of its own is reached through the panel's.
 */
class Proxy
{
    // ponytail: the ports compose.node.yaml publishes by default; make them node fields when a node differs.
    private const int MINECRAFT_PORT = 25565;

    private const int MAPS_PORT = 8100;

    /**
     * Write both route files again, from the servers and nodes as they are now.
     */
    public static function sync(): void
    {
        $folder = config('panel.proxy_dir');

        if (blank($folder)) {
            return;
        }

        $routes = ['mappings' => []];
        $routers = $services = [];

        foreach (Server::query()->with('node')->get() as $server) {
            $node = $server->node;
            $host = parse_url($node->base_url, PHP_URL_HOST);

            // Docker tells both proxies about the servers on the panel's own host. Only the default
            // one is named, by its container: the router forgets it each time it reads this file.
            if ($node->name === Node::LOCAL || ! is_string($host)) {
                if ($server->is_default && $node->name === Node::LOCAL) {
                    $routes['default-server'] = "mc-{$server->slug}:".self::MINECRAFT_PORT;
                }

                continue;
            }

            if (blank($node->dns_target)) {
                $routes['mappings'][$server->hostname] = $host.':'.self::MINECRAFT_PORT;

                if ($server->is_default) {
                    $routes['default-server'] = $host.':'.self::MINECRAFT_PORT;
                }
            }

            if ($server->bluemap_enabled && blank($node->bluemap_url)) {
                // The node's own map proxy takes the server's name off the path.
                $routers["map-{$server->slug}"] = ['rule' => "PathPrefix(`/{$server->slug}/`)", 'entryPoints' => ['bluemap'], 'service' => "node-{$node->id}"];
                $services["node-{$node->id}"] = ['loadBalancer' => ['servers' => [['url' => "http://{$host}:".self::MAPS_PORT]]]];
            }
        }

        $routes['mappings'] = (object) $routes['mappings'];

        // A server must not fail to save because a proxy cannot be told about it.
        rescue(function () use ($folder, $routes, $routers, $services): void {
            File::ensureDirectoryExists("{$folder}/maps");
            // Written in place: the router watches the file itself, and loses a replaced one.
            File::put("{$folder}/minecraft.json", (string) json_encode($routes, JSON_UNESCAPED_SLASHES));
            // JSON is YAML, which is what the map proxy reads.
            File::put("{$folder}/maps/maps.yml", (string) json_encode($routers === [] ? new stdClass : ['http' => ['routers' => $routers, 'services' => $services]], JSON_UNESCAPED_SLASHES));
        });
    }
}
