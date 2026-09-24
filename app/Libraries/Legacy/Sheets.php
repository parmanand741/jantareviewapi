<?php

declare(strict_types=1);

namespace App\Libraries\Legacy;

use Google\Client as GoogleClient;
use Google\Service\Sheets as GoogleSheets;
use Google\Service\Sheets\ValueRange;
use Google\Service\Sheets\BatchUpdateValuesRequest;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
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

    private static function findCaBundle(): ?string
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
     * Read a rectangular range.
     * @return array<int, array<int, mixed>>
     */
    public static function read(string $sheetName, string $range): array
    {
        self::init();
        $full = "'{$sheetName}'!{$range}";
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
     * Append a row.
     */
    public static function append(string $sheetName, array $values): void
    {
        self::init();
        $body = new ValueRange(['values' => [$values]]);
        self::withRetries(function () use ($sheetName, $body): void {
                self::$service->spreadsheets_values->append(
                    self::$spreadsheetId,
                    "'{$sheetName}'!A1",
                    $body,
                    ['valueInputOption' => 'USER_ENTERED', 'insertDataOption' => 'INSERT_ROWS']
                );
        });
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
                ['valueInputOption' => 'USER_ENTERED']
            );
        });
    }

    /**
     * Write a single cell.
     */
    public static function setCell(string $sheetName, string $a1, mixed $value): void
    {
        self::init();
        $body = new ValueRange(['values' => [[$value]]]);
        self::withRetries(function () use ($sheetName, $a1, $body): void {
            self::$service->spreadsheets_values->update(
                self::$spreadsheetId,
                "'{$sheetName}'!{$a1}",
                $body,
                ['valueInputOption' => 'USER_ENTERED']
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
            'valueInputOption' => 'USER_ENTERED',
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
