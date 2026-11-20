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

    private const int KEEP = 3;

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

    // Every page asks; the folder is listed once a minute. An empty string caches "none".
    public static function latestVersion(): ?string
    {
        return Cache::remember('portal-latest-version', 60, fn (): string => array_key_first((new self)->all()) ?? '') ?: null;
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
     * is what makes a release visible, and only the newest few are kept.
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

        foreach (array_slice(array_keys($listed), self::KEEP) as $old) {
            $disk->delete(['portal/'.self::name($old), 'portal/'.substr(self::name($old), 0, -3).'json']);
            unset($listed[$old]);
        }

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
