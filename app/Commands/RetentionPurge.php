<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Legacy\Config;
use App\Libraries\Legacy\Retention;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

final class RetentionPurge extends BaseCommand
{
    protected $group = 'Security';
    protected $name = 'security:retention-purge';
    protected $description = 'Deletes archived content, reports, suggestions and audit rows that are past the published retention period.';
    protected $usage = 'security:retention-purge [--dry-run]';
    protected $arguments = [];
    protected $options = [
        '--dry-run' => 'Report what would be deleted without touching the datastore.',
    ];

    public function run(array $params)
    {
        // spark hands `--dry-run` over as an option key, not a positional arg.
        $dryRun = array_key_exists('dry-run', $params);

        try {
            Config::load();
            $result = Retention::purge($dryRun);

            if ($result['removed'] === []) {
                CLI::write('Nothing is past its retention period.');
            }
            foreach ($result['removed'] as $tab => $count) {
                CLI::write(($dryRun ? 'Would delete ' : 'Deleted ') . $count . ' row(s) from ' . $tab . '.');
            }

            return EXIT_SUCCESS;
        } catch (\Throwable $e) {
            CLI::error('Retention purge failed: ' . $e->getMessage());
            return EXIT_ERROR;
        }
    }
}
