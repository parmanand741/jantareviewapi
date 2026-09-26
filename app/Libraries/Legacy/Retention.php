<?php

declare(strict_types=1);

namespace App\Libraries\Legacy;

use RuntimeException;

/**
 * Enforces the retention promise the Privacy Notice makes: removed content
 * stays in an internal archive for a stated number of days and is then purged.
 * A stated period that nobody enforces is worse than no period at all.
 *
 * Reviews, Votes and the grievance ledgers are deliberately untouched. The
 * vote ledger exists to stop one device voting twice, so purging it would
 * silently reopen long-running entries; grievance records are the one class of
 * data the intermediary rules require keeping rather than dropping.
 */
final class Retention
{
    /**
     * @return array{removed: array<string, int>, dryRun: bool}
     */
    public static function purge(bool $dryRun = false): array
    {
        $lock = self::lock();
        if ($lock === false) {
            throw new RuntimeException('Another purge is already running.');
        }

        try {
            $contentDays = (int) Config::get('retention_days', 90);
            $auditDays = (int) Config::get('audit_retention_days', 180);

            // tab => [range to scan, timestamp columns to try, rows kept]
            $tabs = [
                'deleted' => ['A2:M', [12, 1], $contentDays],
                'reports' => ['A2:B', [1], $contentDays],
                'suggestions' => ['A2:B', [1], $contentDays],
                'modlog' => ['A2:B', [1], $auditDays],
            ];

            $removed = [];
            foreach ($tabs as $key => [$range, $columns, $maxAgeDays]) {
                $sheet = Sheets::sheetName($key);
                $doomed = self::expired(Sheets::read($sheet, $range), $columns, $maxAgeDays);
                if ($doomed === []) continue;

                $removed[$key] = $dryRun ? count($doomed) : Sheets::deleteRows($sheet, $doomed);
            }

            if (!$dryRun && array_sum($removed) > 0) {
                Audit::log([
                    'actionType' => 'retention_purged',
                    'actor' => 'system',
                    'reason' => 'Purged ' . json_encode($removed)
                        . " (content {$contentDays} days, audit {$auditDays} days)",
                ]);
            }

            return ['removed' => $removed, 'dryRun' => $dryRun];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Sheet row numbers older than the horizon. The first readable timestamp
     * wins, so a row whose deletion date is missing is still judged by when it
     * was written; a row with neither is kept, because nothing proves its age.
     *
     * @param array<int, array<int, mixed>> $rows
     * @param array<int, int> $columns
     * @return array<int, int>
     */
    private static function expired(array $rows, array $columns, int $maxAgeDays): array
    {
        if ($maxAgeDays <= 0) return [];
        $cutoff = time() - ($maxAgeDays * 86400);
        $doomed = [];

        foreach ($rows as $offset => $row) {
            if ((string) ($row[0] ?? '') === '') continue;
            foreach ($columns as $column) {
                $at = strtotime(Time::iso($row[$column] ?? ''));
                if ($at === false) continue;
                if ($at < $cutoff) $doomed[] = $offset + 2;
                break;
            }
        }

        return $doomed;
    }

    /** @return resource|false */
    private static function lock()
    {
        $dir = (string) Config::get('storage_path');
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $handle = @fopen($dir . '/purge.lock', 'c');
        if ($handle === false) return false;

        return flock($handle, LOCK_EX | LOCK_NB) ? $handle : false;
    }
}
