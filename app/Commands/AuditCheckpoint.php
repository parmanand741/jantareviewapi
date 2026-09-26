<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Legacy\Audit;
use App\Libraries\Legacy\Config;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Nightly integrity checkpoint for the public moderation log. See Audit.
 */
final class AuditCheckpoint extends BaseCommand
{
    protected $group = 'Security';
    protected $name = 'security:audit-checkpoint';
    protected $description = 'Folds the moderation log into a rolling digest and records it, so that rewriting an old row becomes detectable.';
    protected $usage = 'security:audit-checkpoint [--dry-run]';
    protected $arguments = [];
    protected $options = [
        '--dry-run' => 'Report the digest and any mismatch without recording a checkpoint.',
    ];

    public function run(array $params)
    {
        $dryRun = array_key_exists('dry-run', $params);

        Config::load();
        $record = Audit::checkpoint($dryRun);

        CLI::write(sprintf(
            '%s%s over %d row(s): %s%s',
            $dryRun ? 'Would record ' : '',
            $record['kind'],
            $record['rowCount'],
            substr($record['digest'], 0, 16),
            $record['note'] === '' ? '' : ' — ' . $record['note']
        ));

        if ($record['kind'] === 'break') {
            // Throwing keeps the non-zero exit code, so whatever schedules this
            // job fails loudly instead of reporting a green run over a log that
            // no longer matches its own history.
            throw new \RuntimeException('Moderation log integrity check failed: ' . $record['note']);
        }

        return EXIT_SUCCESS;
    }
}
