<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Legacy\Backup;
use App\Libraries\Legacy\Config;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

final class SheetsBackup extends BaseCommand
{
    protected $group = 'Backup';
    protected $name = 'backup:sheets-export';
    protected $description = 'Writes every datastore tab to CSV with a hash manifest, then drops exports past the keep window.';
    protected $usage = 'backup:sheets-export [--out=<dir>] [--keep=<days>]';
    protected $options = [
        '--out' => 'Destination directory. Defaults to <writable>/backups.',
        '--keep' => 'Days of exports to retain; 0 keeps all. Default 14.',
    ];

    public function run(array $params)
    {
        try {
            Config::load();
            $root = (string) ($params['out'] ?? ((string) Config::get('storage_path') . '/backups'));
            $keep = (int) ($params['keep'] ?? 14);

            $result = Backup::export($root);
            $pruned = Backup::prune($root, $keep);

            foreach ($result['manifest']['tabs'] as $entry) {
                CLI::write(str_pad((string) $entry['title'], 22, ' ') . ' ' . str_pad((string) $entry['rows'], 6) . ' rows  ' . $entry['bytes'] . ' bytes');
            }
            CLI::write('Exported to ' . $result['dir']);
            if ($pruned > 0) {
                CLI::write('Removed ' . $pruned . ' expired export file(s).');
            }

            return EXIT_SUCCESS;
        } catch (\Throwable $e) {
            CLI::error('Sheet export failed: ' . $e->getMessage());
            return EXIT_ERROR;
        }
    }
}
