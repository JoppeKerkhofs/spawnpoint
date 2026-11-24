<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The launcher's installers, kept on the local disk under portal/ with a small JSON file
 * beside each: one per version and system. Paths are built only from versions that matched the
 * pattern and the systems listed here, never from input.
 */
class PortalReleases
{
    // Six digits a part: version_compare saturates on longer numbers, and nothing after could win.
    public const string PARTS = '\d{1,6}\.\d{1,6}\.\d{1,6}';

    public const string VERSION = '/^'.self::PARTS.'\z/';

    // The updater's name of each system, and what its installer's name ends in. Windows comes first.
    public const array PLATFORMS = ['windows-x86_64' => '-setup.exe', 'linux-x86_64' => '-x86_64.AppImage'];

    public const string WINDOWS = 'windows-x86_64';

    public static function name(string $version, string $platform = self::WINDOWS): string
    {
        return "spawnpoint-portal-{$version}".self::PLATFORMS[$platform];
    }

    public static function url(string $version, string $platform = self::WINDOWS): string
    {
        return rtrim((string) config('app.url'), '/').'/portal/'.self::name($version, $platform);
    }

    public static function path(string $version, string $platform = self::WINDOWS): string
    {
        return Storage::disk('local')->path('portal/'.self::name($version, $platform));
    }

    private static function meta(string $version, string $platform): string
    {
        return 'portal/'.pathinfo(self::name($version, $platform), PATHINFO_FILENAME).'.json';
    }

    // The version in an installer's file name, or null when the name is not that system's.
    private static function versionIn(string $name, string $platform): ?string
    {
        $pattern = '/^spawnpoint-portal-('.self::PARTS.')'.preg_quote(self::PLATFORMS[$platform], '/').'\z/';

        return preg_match($pattern, $name, $match) === 1 ? $match[1] : null;
    }

    // The system a browser says it runs on, as far as there is an installer for it.
    public static function platformOf(?string $userAgent): string
    {
        $agent = (string) $userAgent;

        return str_contains($agent, 'Linux') && ! str_contains($agent, 'Android') ? 'linux-x86_64' : self::WINDOWS;
    }

    /**
     * Every stored release by version, newest first, with what is known of each system's installer.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function all(): array
    {
        $disk = Storage::disk('local');
        $files = $disk->files('portal');
        $releases = [];

        foreach (array_keys(self::PLATFORMS) as $platform) {
            foreach ($files as $file) {
                $version = self::versionIn(basename($file), $platform);
                $meta = $version === null ? null : json_decode((string) $disk->get(self::meta($version, $platform)), true);

                if (is_array($meta)) {
                    $releases[$version][$platform] = $meta;
                }
            }
        }
        uksort($releases, fn (string $a, string $b): int => version_compare($b, $a));

        return $releases;
    }

    /**
     * Whether a version may be downloaded. Its installers all carry the same answer.
     *
     * @param  array<string, array<string, mixed>>  $installers
     */
    private static function allowed(array $installers): bool
    {
        return (reset($installers)['downloadable'] ?? true) === true;
    }

    /**
     * The releases anyone may download, newest first. The first is what the updater is offered.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function downloadable(): array
    {
        return array_filter($this->all(), self::allowed(...));
    }

    /**
     * What the pages show of every release; no address for one that may not be downloaded. The
     * version's own size and address are those of its first installer.
     *
     * @return list<array{version: string, released: mixed, size: mixed, url: string|null, files: list<array{platform: string, size: mixed, url: string|null}>}>
     */
    public function listing(): array
    {
        $list = [];
        foreach ($this->all() as $version => $installers) {
            $files = [];
            foreach ($installers as $platform => $meta) {
                $files[] = [
                    'platform' => $platform,
                    'size' => $meta['size'] ?? null,
                    'url' => self::allowed($installers) ? self::url((string) $version, $platform) : null,
                ];
            }
            $list[] = [
                'version' => (string) $version,
                'released' => reset($installers)['uploaded_at'] ?? null,
                'size' => $files[0]['size'],
                'url' => $files[0]['url'],
                'files' => $files,
            ];
        }

        return $list;
    }

    // Every page asks; the folder is listed once a minute. An empty string caches "none".
    public static function latestVersion(): ?string
    {
        return Cache::remember('portal-latest-version', 60, fn (): string => (string) (array_key_first((new self)->downloadable()) ?? '')) ?: null;
    }

    // Keep a release listed but refuse its downloads, or allow them again.
    public function mark(string $version, bool $downloadable): void
    {
        foreach ($this->all()[$version] ?? [] as $platform => $meta) {
            Storage::disk('local')->put(self::meta($version, $platform), (string) json_encode([...$meta, 'downloadable' => $downloadable]));
        }
        Cache::forget('portal-latest-version');
    }

    // The JSON files go first: without them the release is no longer listed.
    public function delete(string $version): void
    {
        $platforms = array_keys(self::PLATFORMS);
        Storage::disk('local')->delete([
            ...array_map(fn (string $platform): string => self::meta($version, $platform), $platforms),
            ...array_map(fn (string $platform): string => 'portal/'.self::name($version, $platform), $platforms),
        ]);
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
     * Make the temporary file the release's installer for one system. The installer is renamed
     * before its JSON file, which is what makes it visible. A version that may not be downloaded
     * stays that way. Old releases stay until someone removes them.
     *
     * @param  array<string, mixed>  $meta
     */
    public function publish(string $version, string $platform, string $temp, array $meta): void
    {
        $disk = Storage::disk('local');
        $installers = $this->all()[$version] ?? [];
        if ($installers !== [] && ! self::allowed($installers)) {
            $meta['downloadable'] = false;
        }

        file_put_contents($temp.'.json', json_encode($meta));
        rename($temp, self::path($version, $platform));
        rename($temp.'.json', $disk->path(self::meta($version, $platform)));

        Cache::forget('portal-latest-version');

        $listed = $this->all();

        // What a crashed upload left: temporary files past an hour, and an installer without its JSON.
        foreach ($disk->files('portal') as $file) {
            $name = basename($file);
            $cutOff = str_starts_with($name, '.upload-') && $disk->lastModified($file) < now()->subHour()->getTimestamp();
            $orphan = false;
            foreach (array_keys(self::PLATFORMS) as $system) {
                $of = self::versionIn($name, $system);
                $orphan = $orphan || ($of !== null && ! isset($listed[$of][$system]));
            }

            if ($cutOff || $orphan) {
                $disk->delete($file);
            }
        }
    }
}
