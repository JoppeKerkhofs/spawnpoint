<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CloudflareDns
{
    private const string API = 'https://api.cloudflare.com/client/v4';

    /**
     * DNS records are managed when both an API token and a target are filled in on the Settings page.
     */
    public static function enabled(): bool
    {
        return filled(Setting::value('cloudflare_token')) && filled(Setting::value('dns_target'));
    }

    /**
     * Add the record for a server's hostname, pointing at the target. Not proxied: Cloudflare's
     * proxy carries web traffic only, so a proxied record would break joining.
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public static function create(string $hostname): void
    {
        $target = (string) Setting::value('dns_target');
        $type = match (true) {
            filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false => 'A',
            filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false => 'AAAA',
            default => 'CNAME',
        };

        $response = self::http()->post(self::API.'/zones/'.self::zone($hostname).'/dns_records', [
            'type' => $type, 'name' => $hostname, 'content' => $target, 'ttl' => 1, 'proxied' => false,
            'comment' => 'Minecraft panel',
        ]);

        // Codes 81057 and 81058 mean an identical record is already there, which is the wanted outcome.
        if (in_array($response->json('errors.0.code'), [81057, 81058], true)) {
            return;
        }

        $response->throw();
    }

    /**
     * Remove the records for a server's hostname.
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public static function delete(string $hostname): void
    {
        $zone = self::zone($hostname);
        $records = self::http()->get(self::API."/zones/{$zone}/dns_records", ['name' => $hostname])->throw()->json('result');

        foreach ($records as $record) {
            self::http()->delete(self::API."/zones/{$zone}/dns_records/{$record['id']}")->throw();
        }
    }

    /**
     * Find the zone a hostname belongs to: "survival.mc.example.com" is looked for as
     * "mc.example.com", then "example.com".
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    private static function zone(string $hostname): string
    {
        $labels = explode('.', $hostname);

        for ($from = 1; $from <= count($labels) - 2; $from++) {
            $domain = implode('.', array_slice($labels, $from));
            $id = Cache::get("cloudflare-zone:{$domain}")
                ?? self::http()->get(self::API.'/zones', ['name' => $domain])->throw()->json('result.0.id');

            if ($id !== null) {
                Cache::put("cloudflare-zone:{$domain}", $id, now()->addHour());

                return $id;
            }
        }

        throw new ConnectionException("No Cloudflare zone holds {$hostname}; check the token's zone access.");
    }

    private static function http(): PendingRequest
    {
        return Http::connectTimeout(3)->timeout(10)->withToken((string) Setting::value('cloudflare_token'));
    }
}
