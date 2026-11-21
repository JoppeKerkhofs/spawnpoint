<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The launcher's installers, kept on the local disk under portal/ with a small JSON file
 * beside each. Paths are built only from versions that matched the pattern, never from input.
 */
class PortalReleases
{
    // Six digits a part: version_compare saturates on longer numbers, and nothing after could win.
    public const string PARTS = '\d{1,6}\.\d{1,6}\.\d{1,6}';

    public const string VERSION = '/^'.self::PARTS.'\z/';

    private const string INSTALLER = '/^spawnpoint-portal-('.self::PARTS.')-setup\.exe\z/';

    public static function name(string $version): string
    {
        return "spawnpoint-portal-{$version}-setup.exe";
    }

    public static function url(string $version): string
    {
        return rtrim((string) config('app.url'), '/').'/portal/'.self::name($version);
    }

    public static function path(string $version): string
    {
        return Storage::disk('local')->path('portal/'.self::name($version));
    }

    /**
     * Every stored release by version, newest first.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $disk = Storage::disk('local');
        $releases = [];

        foreach ($disk->files('portal') as $file) {
            $meta = preg_match(self::INSTALLER, basename($file), $match) === 1
                ? json_decode((string) $disk->get(substr($file, 0, -3).'json'), true)
                : null;

            if (is_array($meta)) {
                $releases[$match[1]] = $meta;
            }
        }
        uksort($releases, fn (string $a, string $b): int => version_compare($b, $a));

        return $releases;
    }

    /**
     * The releases anyone may download, newest first. The first is what the updater is offered.
     *
     * @return array<string, array<string, mixed>>
     */
    public function downloadable(): array
    {
        return array_filter($this->all(), fn (array $meta): bool => ($meta['downloadable'] ?? true) === true);
    }

    /**
     * What the pages show of every release; no address for one that may not be downloaded.
     *
     * @return list<array{version: string, released: mixed, size: mixed, url: string|null}>
     */
    public function listing(): array
    {
        $list = [];
        foreach ($this->all() as $version => $meta) {
            $list[] = [
                'version' => (string) $version,
                'released' => $meta['uploaded_at'] ?? null,
                'size' => $meta['size'] ?? null,
                'url' => ($meta['downloadable'] ?? true) === true ? self::url((string) $version) : null,
            ];
        }

        return $list;
    }

    // Every page asks; the folder is listed once a minute. An empty string caches "none".
    public static function latestVersion(): ?string
    {
        return Cache::remember('portal-latest-version', 60, fn (): string => array_key_first((new self)->downloadable()) ?? '') ?: null;
    }

    /**
     * Keep a release listed but refuse its download, or allow it again.
     *
     * @param  array<string, mixed>  $meta
     */
    public function mark(string $version, array $meta, bool $downloadable): void
    {
        Storage::disk('local')->put('portal/'.substr(self::name($version), 0, -3).'json', (string) json_encode([...$meta, 'downloadable' => $downloadable]));
        Cache::forget('portal-latest-version');
    }

    // The JSON file goes first: without it the release is no longer listed.
    public function delete(string $version): void
    {
        Storage::disk('local')->delete(['portal/'.substr(self::name($version), 0, -3).'json', 'portal/'.self::name($version)]);
        Cache::forget('portal-latest-version');
    }

    /**
     * Copy the stream into a temporary file, at most $max bytes. Null when it is longer.
     *
     * @param  resource  $stream
     */
    public function receive($stream, int $max): ?string
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory('portal');
        $temp = $disk->path('portal/.upload-'.bin2hex(random_bytes(8)));
        $out = fopen($temp, 'wb') ?: throw new RuntimeException('The upload could not be started.');
        $size = 0;

        try {
            while (! feof($stream) && ($chunk = fread($stream, 1 << 20)) !== false) {
                $size += strlen($chunk);
                if ($size > $max) {
                    unlink($temp);

                    return null;
                }
                if (fwrite($out, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('The upload could not be written to disk.');
                }
            }
        } catch (\Throwable $e) {
            @unlink($temp);

            throw $e;
        } finally {
            fclose($out);
        }

        return $temp;
    }

    /**
     * Make the temporary file the release. The installer is renamed before its JSON file, which
     * is what makes a release visible. Old releases stay until someone removes them.
     *
     * @param  array<string, mixed>  $meta
     */
    public function publish(string $version, string $temp, array $meta): void
    {
        $installer = self::path($version);

        file_put_contents($temp.'.json', json_encode($meta));
        rename($temp, $installer);
        rename($temp.'.json', substr($installer, 0, -3).'json');

        Cache::forget('portal-latest-version');

        $disk = Storage::disk('local');
        $listed = $this->all();

        // What a crashed upload left: temporary files past an hour, and an installer without its JSON.
        foreach ($disk->files('portal') as $file) {
            $name = basename($file);
            $cutOff = str_starts_with($name, '.upload-') && $disk->lastModified($file) < now()->subHour()->getTimestamp();
            $orphan = preg_match(self::INSTALLER, $name, $match) === 1 && ! isset($listed[$match[1]]);

            if ($cutOff || $orphan) {
                $disk->delete($file);
            }
        }
    }
}
