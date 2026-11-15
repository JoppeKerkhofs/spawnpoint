<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

#[Signature('lang:sync {locale : The language code, such as nl}')]
#[Description('Create or update lang/<locale>.json with every text of the panel that has no translation yet')]
class SyncLanguage extends Command
{
    // Texts that reach t() through a variable, so the scan of the code cannot see them: server
    // states, action names, server types, and the buttons and labels of the dialogs.
    private const array DYNAMIC = [
        'running', 'starting', 'stopping', 'offline', 'error', 'installing', 'restarting', 'restoring',
        'importing', 'backing up', 'deleting', 'loading', 'unknown',
        'Start', 'Stop', 'Restart', 'Kill', 'Backup', 'Command',
        'Vanilla', 'Paper', 'Fabric', 'Forge', 'NeoForge', 'Pumpkin (Rust)', 'Modrinth modpack', 'CurseForge modpack', 'FTB modpack',
        'Delete', 'Revoke', 'Replace', 'Restore', 'Create', 'Rename', 'File name', 'Folder name', 'New name',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $locale = (string) $this->argument('locale');

        if (preg_match('/^[A-Za-z_-]{2,10}$/', $locale) !== 1 || $locale === 'en') {
            $this->error('Give a language code such as nl or pt_BR. English needs no file: it is what the code is written in.');

            return self::FAILURE;
        }

        $file = lang_path("{$locale}.json");
        $known = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        $added = 0;

        foreach (self::texts() as $text) {
            if (! array_key_exists($text, $known)) {
                // Untranslated entries hold the English text, so the panel keeps working until someone fills them in.
                $known[$text] = $text;
                $added++;
            }
        }

        $known = ['@language' => $known['@language'] ?? $locale] + $known;

        if (! is_dir(lang_path())) {
            mkdir(lang_path(), 0755, true);
        }
        file_put_contents($file, json_encode($known, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

        $this->info("{$file}: {$added} new texts, ".(count($known) - 1).' in total. Set "@language" to the name of the language.');

        return self::SUCCESS;
    }

    /**
     * Every text the interface passes to t(), ask() or askName(), and the backend to __() or trans_choice().
     *
     * @return list<string>
     */
    public static function texts(): array
    {
        $texts = [];
        $files = Finder::create()->files()->name(['*.vue', '*.ts', '*.php'])
            ->in([resource_path('js'), app_path(), base_path('bootstrap')])
            ->exclude(['routes', 'actions', 'wayfinder']);

        foreach ($files as $file) {
            // A text in single, double or backtick quotes, without ${...} in it.
            preg_match_all('/(?<![\w.$>])(?:t|ask|askName|__|trans_choice)\(\s*([\'"`])((?:\\\\.|(?!\1)[^\\\\$]|\$(?!\{))+)\1/', $file->getContents(), $matches);

            foreach ($matches[2] as $text) {
                $texts[] = stripslashes($text);
            }

            // A page's title, description and breadcrumbs are plain texts in its options; the layout translates them.
            if ($file->getExtension() === 'vue') {
                preg_match_all('/^\s+(?:title|description):\s*\'([^\'\\\\]+)\',?$/m', $file->getContents(), $options);

                foreach ($options[1] as $text) {
                    $texts[] = $text;
                }
            }
        }

        $texts = array_values(array_unique([...$texts, ...self::DYNAMIC]));
        sort($texts);

        return $texts;
    }
}
