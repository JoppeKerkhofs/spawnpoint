<?php

namespace App\Services;

class Languages
{
    /**
     * The languages the panel can be shown in: English, which is what the code is written in,
     * plus every lang/<code>.json file. A file names itself with its "@language" entry.
     *
     * @return array<string, string> Code to name, such as "nl" to "Nederlands".
     */
    public static function available(): array
    {
        $languages = ['en' => 'English'];

        foreach (glob(lang_path('*.json')) ?: [] as $file) {
            $code = basename($file, '.json');
            $languages[$code] = self::translations($code)['@language'] ?? $code;
        }

        return $languages;
    }

    /**
     * The translations of a language, keyed by the English text. Empty for English.
     *
     * @return array<string, string>
     */
    public static function translations(string $code): array
    {
        $file = lang_path("{$code}.json");

        // The code comes from a cookie-less user setting, but it still must never reach the filesystem unchecked.
        if (preg_match('/^[A-Za-z_-]{2,10}$/', $code) !== 1 || ! is_file($file)) {
            return [];
        }

        return json_decode((string) file_get_contents($file), true) ?: [];
    }
}
