<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Legacy\Backup;
use App\Libraries\Legacy\Config;
use App\Libraries\Legacy\Sheets;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Restores an export into a fresh set of tabs named after a prefix, never over
 * the live ones. A backup whose restore has never been run is a guess, so this
 * exists to be pointed at a scratch spreadsheet and rehearsed.
 */
final class SheetsRestore extends BaseCommand
{
    protected $group = 'Backup';
    protected $name = 'backup:sheets-restore';
    protected $description = 'Writes a CSV export back into new <prefix>_<tab> sheets so a restore can be rehearsed safely.';
    protected $usage = 'backup:sheets-restore --from=<dir> --prefix=<name>';
    protected $arguments = [];
    protected $options = [
        '--from' => 'Export directory containing _manifest.json.',
        '--prefix' => 'Sheet-name prefix, e.g. REHEARSAL-2026-09-26.',
    ];

    public function run(array $params)
    {
        try {
            Config::load();
            $dir = (string) ($params['from'] ?? '');
            $prefix = trim((string) ($params['prefix'] ?? ''));
            if ($dir === '' || $prefix === '') {
                CLI::error('Both --from and --prefix are required.');
                return EXIT_ERROR;
            }

            $existing = Sheets::tabIds();
            foreach (Backup::readExport($dir) as $title => $rows) {
                $target = $prefix . '_' . $title;
                if (isset($existing[$target])) {
                    CLI::error("Refusing to touch the existing tab '{$target}'.");
                    return EXIT_ERROR;
                }

                $data = Backup::rowsFrom($dir, $title);
                Sheets::addSheet($target);
                if ($data !== []) {
                    $width = max(array_map(static fn(array $r): int => count($r), $data));
                    $padded = array_map(static fn(array $r): array => array_pad($r, $width, ''), $data);
                    Sheets::write($target, 'A1:' . Sheets::columnLetter($width) . count($padded), $padded);
                }

                CLI::write("Restored {$rows} row(s) into '{$target}'.");
            }

            CLI::write('Compare each tab against its live version before promoting anything.');
            return EXIT_SUCCESS;
        } catch (\Throwable $e) {
            CLI::error('Sheet restore failed: ' . $e->getMessage());
            return EXIT_ERROR;
        }
    }
}
