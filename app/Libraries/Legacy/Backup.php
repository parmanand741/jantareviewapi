<?php

declare(strict_types=1);

namespace App\Libraries\Legacy;

use RuntimeException;

/**
 * Point-in-time copies of every tab, written as CSV next to a hash manifest.
 *
 * The values are reproduced byte-for-byte rather than escaped for spreading
 * software, because a backup you cannot restore is not a backup. That does
 * mean an exported cell that begins with '=' is a live formula the moment a
 * person opens the CSV in a spreadsheet app, so these files are for the shell
 * and the restore command, not for browsing.
 */
final class Backup
{
    /**
     * @return array{dir: string, manifest: array<string, mixed>}
     */
    public static function export(string $root): array
    {
        $dir = rtrim($root, '/\\') . '/' . gmdate('Y-m-d\TH-i-s\Z');
        if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
            throw new RuntimeException("Cannot create export directory {$dir}.");
        }

        $manifest = [
            'exportedAt' => Time::nowIso(),
            'spreadsheetId' => (string) Config::get('spreadsheet_id'),
            'tabs' => [],
        ];

        foreach (array_keys(Sheets::tabIds()) as $title) {
            $rows = Sheets::read($title, '');
            $csv = self::toCsv($rows);
            $file = $dir . '/' . self::slug($title) . '.csv';
            if (file_put_contents($file, $csv) === false) {
                throw new RuntimeException("Cannot write {$file}.");
            }

            // What landed on disk must be what was read: a silently truncated
            // write would otherwise only show up during the restore.
            if (hash('sha256', (string) file_get_contents($file)) !== hash('sha256', $csv)) {
                throw new RuntimeException("Verification failed for {$file}.");
            }

            $manifest['tabs'][self::slug($title)] = [
                'title' => $title,
                'rows' => count($rows),
                'sha256' => hash('sha256', $csv),
                'bytes' => strlen($csv),
            ];
        }

        file_put_contents(
            $dir . '/_manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );

        return ['dir' => $dir, 'manifest' => $manifest];
    }

    /** Deletes export directories (and their loose archives) older than $keepDays. */
    public static function prune(string $root, int $keepDays): int
    {
        if ($keepDays <= 0) return 0;
        $cutoff = time() - ($keepDays * 86400);
        $removed = 0;

        foreach (glob(rtrim($root, '/\\') . '/*', GLOB_NOSORT) ?: [] as $path) {
            if (is_file($path) && !str_ends_with($path, '.json')) continue;
            if (!str_ends_with(basename($path), 'Z') || filemtime($path) > $cutoff) continue;
            $removed += self::deleteTree($path);
        }

        return $removed;
    }

    /** @return array<int, int> tab name => row count, for the ones a restore would write */
    public static function readExport(string $dir): array
    {
        $manifest = json_decode((string) file_get_contents($dir . '/_manifest.json'), true);
        if (!is_array($manifest) || empty($manifest['tabs'])) {
            throw new RuntimeException('No readable _manifest.json in ' . $dir);
        }

        $counts = [];
        foreach ($manifest['tabs'] as $slug => $tab) {
            $rows = self::fromCsv((string) file_get_contents($dir . '/' . $slug . '.csv'));
            if (count($rows) !== (int) ($tab['rows'] ?? -1)) {
                throw new RuntimeException("Row count for {$slug} does not match the manifest.");
            }
            $counts[(string) ($tab['title'] ?? $slug)] = count($rows);
        }

        return $counts;
    }

    /**
     * Rows as they were exported, ready to write back with RAW input option.
     *
     * @return array<int, array<int, mixed>>
     */
    public static function rowsFrom(string $dir, string $title): array
    {
        return self::fromCsv((string) file_get_contents($dir . '/' . self::slug($title) . '.csv'));
    }

    /** @param array<int, array<int, mixed>> $rows */
    public static function toCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                static fn(mixed $v): string => match (true) {
                    $v === null => '',
                    is_array($v) => (string) json_encode($v),
                    default => (string) $v,
                },
                array_values($row)
            ));
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public static function fromCsv(string $csv): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null]) continue;
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    private static function slug(string $title): string
    {
        return preg_replace('~[^A-Za-z0-9._-]+~', '_', $title) ?? $title;
    }

    private static function deleteTree(string $path): int
    {
        if (!is_dir($path)) return is_file($path) && @unlink($path) ? 1 : 0;

        $count = 0;
        foreach (glob($path . '/*', GLOB_NOSORT) ?: [] as $child) {
            $count += self::deleteTree($child);
        }

        return $count + (@rmdir($path) ? 1 : 0);
    }
}
