<?php

declare(strict_types=1);

namespace App\Libraries\Legacy;

use Google\Client as GoogleClient;
use Google\Service\Sheets as GoogleSheets;
use Google\Service\Sheets\ValueRange;
use Google\Service\Sheets\BatchUpdateValuesRequest;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\DeleteDimensionRequest;
use Google\Service\Sheets\DimensionRange;
use Google\Service\Sheets\Request as SheetsRequest;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\TransferException;
use RuntimeException;

final class Sheets
{
    private static ?GoogleSheets $service = null;
    private static string $spreadsheetId = '';

    public static function init(): void
    {
        if (self::$service !== null) return;

        $client = new GoogleClient();
        $client->setAuthConfig((string) Config::get('credentials_path'));
        $client->addScope(GoogleSheets::SPREADSHEETS);
        $client->setApplicationName('JantaReview Backend');

        $caBundle = self::findCaBundle();
        if ($caBundle !== null) {
            $client->setHttpClient(new GuzzleClient([
                'verify' => $caBundle,
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_FORBID_REUSE => true,
                    CURLOPT_FRESH_CONNECT => true,
                ],
            ]));
        }

        self::$service = new GoogleSheets($client);
        self::$spreadsheetId = (string) Config::get('spreadsheet_id');
    }

    public static function findCaBundle(): ?string
    {
        $candidates = [
            (string) ini_get('curl.cainfo'),
            (string) ini_get('openssl.cafile'),
            (string) env('REVIEW_CA_BUNDLE', ''),
            'C:/Program Files/Git/mingw64/etc/ssl/certs/ca-bundle.crt',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    public static function sheetName(string $key): string
    {
        return (string) Config::get('sheets')[$key];
    }

    /**
     * Read a rectangular range. An empty range asks for the whole tab.
     * @return array<int, array<int, mixed>>
     */
    public static function read(string $sheetName, string $range): array
    {
        self::init();
        $full = $range === '' ? "'{$sheetName}'" : "'{$sheetName}'!{$range}";
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            try {
                $resp = self::$service->spreadsheets_values->get(self::$spreadsheetId, $full);
                return $resp->getValues() ?? [];
            } catch (TransferException $e) {
                if ($attempt === 6) throw $e;
                self::$service = null;
                self::init();
                usleep(250000 * $attempt);
            } catch (\Google\Service\Exception $e) {
                // Empty sheets return an error sometimes; treat as empty.
                if ($e->getCode() === 400 || $e->getCode() === 404) return [];
                if (self::isRetryable($e) && $attempt < 6) {
                    self::retryDelay($attempt);
                    continue;
                }
                throw $e;
            }
        }

        throw new RuntimeException('Google Sheets read failed after retries.');
    }

    /**
     * Append a row. RAW is the default: USER_ENTERED makes Sheets reparse
     * strings that look like dates, numbers or comma-separated lists, which
     * silently corrupts timestamps and epoch hit lists.
     */
    /**
     * Append one row and return its row number, or null when the API did not
     * tell us. Callers that have to stamp a field on the row they just created
     * would otherwise need a second read to find it.
     */
    public static function append(string $sheetName, array $values, string $inputOption = 'RAW'): ?int
    {
        self::init();
        $body = new ValueRange(['values' => [$values]]);
        $updatedRange = null;
        self::withRetries(function () use ($sheetName, $body, $inputOption, &$updatedRange): void {
            $response = self::$service->spreadsheets_values->append(
                self::$spreadsheetId,
                "'{$sheetName}'!A1",
                $body,
                ['valueInputOption' => $inputOption, 'insertDataOption' => 'INSERT_ROWS']
            );
            try {
                $updatedRange = $response->getUpdates()?->getUpdatedRange();
            } catch (\Throwable $e) {
                $updatedRange = null;
            }
        });

        if (is_string($updatedRange) && preg_match('/![A-Z]+(\d+):/', $updatedRange, $m) === 1) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * Write a full range.
     */
    public static function write(string $sheetName, string $range, array $values): void
    {
        self::init();
        $body = new ValueRange(['values' => $values]);
        self::withRetries(function () use ($sheetName, $range, $body): void {
            self::$service->spreadsheets_values->update(
                self::$spreadsheetId,
                "'{$sheetName}'!{$range}",
                $body,
                ['valueInputOption' => 'RAW']
            );
        });
    }

    /**
     * Write a single cell.
     */
    public static function setCell(string $sheetName, string $a1, mixed $value, string $inputOption = 'RAW'): void
    {
        self::init();
        $body = new ValueRange(['values' => [[$value]]]);
        self::withRetries(function () use ($sheetName, $a1, $body, $inputOption): void {
            self::$service->spreadsheets_values->update(
                self::$spreadsheetId,
                "'{$sheetName}'!{$a1}",
                $body,
                ['valueInputOption' => $inputOption]
            );
        });
    }

    /**
     * Update multiple non-contiguous ranges in one Sheets API request.
     *
     * @param array<int, array{range: string, values: array<int, array<int, mixed>>}> $updates
     */
    public static function batchWrite(string $sheetName, array $updates): void
    {
        if ($updates === []) {
            return;
        }

        self::init();
        $data = array_map(
            static fn(array $update): ValueRange => new ValueRange([
                'range' => "'{$sheetName}'!{$update['range']}",
                'values' => $update['values'],
            ]),
            $updates
        );
        $body = new BatchUpdateValuesRequest([
            'valueInputOption' => 'RAW',
            'data' => $data,
        ]);

        self::withRetries(function () use ($body): void {
            self::$service->spreadsheets_values->batchUpdate(
                self::$spreadsheetId,
                $body
            );
        });
    }

    /**
     * Clear a range.
     */
    public static function clear(string $sheetName, string $range): void
    {
        self::init();
        self::withRetries(function () use ($sheetName, $range): void {
            self::$service->spreadsheets_values->clear(
                self::$spreadsheetId,
                "'{$sheetName}'!{$range}",
                new \Google\Service\Sheets\ClearValuesRequest()
            );
        });
    }

    /**
     * Delete whole rows in one batchUpdate.
     *
     * A clear-then-write of the survivors loses the entire tab if the second
     * call fails and reparses every stored value on the way back in, so rows
     * are removed where they stand. Contiguous numbers are merged and applied
     * high-to-low because requests in one batchUpdate see the deletions that
     * already ran.
     *
     * @param array<int, int> $rowNumbers one-based sheet row numbers
     */
    public static function deleteRows(string $sheetName, array $rowNumbers): int
    {
        $rows = array_values(array_unique(array_filter(array_map('intval', $rowNumbers), static fn(int $r): bool => $r > 1)));
        if ($rows === []) {
            return 0;
        }

        self::init();
        sort($rows);

        $spans = [];
        $start = $end = $rows[0];
        $count = count($rows);
        for ($i = 1; $i < $count; $i++) {
            if ($rows[$i] === $end + 1) {
                $end = $rows[$i];
                continue;
            }
            $spans[] = [$start, $end];
            $start = $end = $rows[$i];
        }
        $spans[] = [$start, $end];

        $sheetId = self::sheetId($sheetName);
        $requests = [];
        foreach (array_reverse($spans) as [$from, $to]) {
            $requests[] = new SheetsRequest([
                'deleteDimension' => new DeleteDimensionRequest([
                    'range' => new DimensionRange([
                        'sheetId' => $sheetId,
                        'dimension' => 'ROWS',
                        // one-based inclusive rows become zero-based half-open indexes
                        'startIndex' => $from - 1,
                        'endIndex' => $to,
                    ]),
                ]),
            ]);
        }

        $body = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
        // Deliberately one attempt. These requests carry absolute row numbers,
        // so a retry after a lost response would delete whatever now sits at
        // those indices — the rows below have shifted up. A failed purge is
        // loud and repeats next night; a mis-aimed one is silent data loss.
        self::$service->spreadsheets->batchUpdate(self::$spreadsheetId, $body);

        return count($rows);
    }

    /**
     * Create an empty tab. Used by the restore rehearsal, which writes a
     * backup into a quarantine tab rather than over live rows.
     */
    public static function addSheet(string $sheetName): void
    {
        self::init();
        if (array_key_exists($sheetName, self::tabIds())) {
            throw new RuntimeException("Sheet '{$sheetName}' already exists.");
        }

        $req = new SheetsRequest([
            'addSheet' => ['properties' => ['title' => $sheetName]],
        ]);
        self::withRetries(function () use ($req): void {
            self::$service->spreadsheets->batchUpdate(
                self::$spreadsheetId,
                new BatchUpdateSpreadsheetRequest(['requests' => [$req]])
            );
        });
    }

    /** One-based column index as it appears in an A1 range. */
    public static function columnLetter(int $index): string
    {
        $letter = '';
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $index = intdiv($index - $mod, 26);
        }

        return $letter === '' ? 'A' : $letter;
    }

    private static function sheetId(string $sheetName): int
    {
        foreach (self::tabIds() as $title => $id) {
            if ($title === $sheetName) {
                return $id;
            }
        }

        throw new RuntimeException("Sheet '{$sheetName}' does not exist.");
    }

    /** @return array<string, int> tab title => numeric sheet id, in sheet order */
    public static function tabIds(): array
    {
        self::init();
        $spreadsheet = null;
        self::withRetries(function () use (&$spreadsheet): void {
            $spreadsheet = self::$service->spreadsheets->get(
                self::$spreadsheetId,
                ['fields' => 'sheets.properties.title,sheets.properties.sheetId']
            );
        });

        $ids = [];
        foreach ($spreadsheet->getSheets() as $sheet) {
            $ids[(string) $sheet->getProperties()->getTitle()] = (int) $sheet->getProperties()->getSheetId();
        }

        return $ids;
    }

    private static function withRetries(callable $operation): void
    {
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            try {
                $operation();
                return;
            } catch (TransferException $e) {
                if ($attempt === 6) throw $e;
                self::retryDelay($attempt);
            } catch (\Google\Service\Exception $e) {
                if (!self::isRetryable($e) || $attempt === 6) {
                    throw $e;
                }
                self::retryDelay($attempt);
            }
        }

        throw new RuntimeException('Google Sheets operation failed after retries.');
    }

    private static function isRetryable(\Google\Service\Exception $exception): bool
    {
        return in_array($exception->getCode(), [408, 429, 500, 502, 503, 504], true);
    }

    private static function retryDelay(int $attempt): void
    {
        self::$service = null;
        self::init();
        $baseSeconds = min(8, 2 ** ($attempt - 1));
        $jitterMicros = random_int(0, 250000);
        usleep(($baseSeconds * 1000000) + $jitterMicros);
    }

    /**
     * Ensure a sheet exists with a header row.
     */
    public static function ensureSheet(string $sheetName, array $headers): void
    {
        self::init();
        $spreadsheet = null;
        self::withRetries(function () use (&$spreadsheet): void {
            $spreadsheet = self::$service->spreadsheets->get(self::$spreadsheetId);
        });
        $existing = array_map(fn($s) => $s->getProperties()->getTitle(), $spreadsheet->getSheets());
        if (!in_array($sheetName, $existing, true)) {
            $req = new SheetsRequest([
                'addSheet' => ['properties' => ['title' => $sheetName]],
            ]);
            self::withRetries(function () use ($req): void {
                self::$service->spreadsheets->batchUpdate(
                    self::$spreadsheetId,
                    new BatchUpdateSpreadsheetRequest(['requests' => [$req]])
                );
            });
            self::append($sheetName, $headers);
        }
    }
}
