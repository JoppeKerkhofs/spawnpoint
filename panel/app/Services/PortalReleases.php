<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The launcher's installers, kept on the local disk under portal/ with a small JSON file
 * beside each. Paths are built only from versions that matched the pattern, never from input.
 */
class PortalReleases
{
    public const string VERSION = '/^\d+\.\d+\.\d+\z/';

    private const string INSTALLER = '/^spawnpoint-portal-(\d+\.\d+\.\d+)-setup\.exe\z/';

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

        foreach (array_slice(array_keys($this->all()), self::KEEP) as $old) {
            Storage::disk('local')->delete(['portal/'.self::name($old), 'portal/'.substr(self::name($old), 0, -3).'json']);
        }
    }
}
