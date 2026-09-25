<?php

declare(strict_types=1);

namespace App\Libraries\Legacy;

final class Turnstile
{
    /**
     * @param string $remoteIp The address that is submitting right now. Cloudflare
     *                         compares it with the one that solved the challenge,
     *                         so a token bought elsewhere and replayed here stops
     *                         verifying. Pass '' only when it is genuinely unknown.
     */
    public static function verify(?string $token, string $expectedAction = '', string $remoteIp = ''): array
    {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $testMode = (bool) Config::get('turnstile_test_mode', false);
        if ((bool) Config::get('allow_local_turnstile_bypass', false)
            && in_array(strtok($host, ':'), ['localhost', '127.0.0.1', 'reviews.local'], true)) {
            return ['ok' => true, 'reason' => 'local-debug'];
        }

        if (!$token || !is_string($token)) {
            return ['ok' => false, 'reason' => 'missing-token'];
        }

        $secret = (string) Config::get('turnstile_secret');
        $fields = ['secret' => $secret, 'response' => $token];
        if ($remoteIp !== '' && filter_var($remoteIp, FILTER_VALIDATE_IP)) {
            $fields['remoteip'] = $remoteIp;
        }
        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        ]);
        $caBundle = Sheets::findCaBundle();
        if ($caBundle !== null) {
            curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
        }
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'reason' => 'curl: ' . $err];
        }
        $r = json_decode((string) $body, true);
        if (!is_array($r) || empty($r['success'])) {
            $codes = is_array($r['error-codes'] ?? null) ? implode(', ', $r['error-codes']) : 'unknown';
            return ['ok' => false, 'reason' => $codes];
        }

        // The sitekey is public, so anyone can embed it on their own page and
        // solve it there. The hostname Cloudflare reports is the only thing
        // that ties the token to our site, so a missing one is as suspicious
        // as a foreign one.
        $issuedFor = strtolower((string) ($r['hostname'] ?? ''));
        $allowed = array_map('strtolower', (array) Config::get('allowed_turnstile_hosts', []));
        if (!$testMode && $allowed && !in_array($issuedFor, $allowed, true)) {
            return ['ok' => false, 'reason' => 'hostname-' . ($issuedFor === '' ? 'missing' : 'not-allowed:' . $issuedFor)];
        }

        // Every widget on the site is rendered with an explicit action, so a
        // token that arrives without one did not come from our form.
        if ($expectedAction !== '' && !$testMode && ($r['action'] ?? '') !== $expectedAction) {
            return ['ok' => false, 'reason' => 'action-mismatch:' . (string) ($r['action'] ?? 'none')];
        }

        return ['ok' => true, 'reason' => ''];
    }
}

final class RateLimit
{
    private static bool $tabChecked = false;

    /**
     * Sliding-window limiter persisted in the Rate_Limits Google Sheet tab so
     * quotas survive Render free-plan cold starts that wipe writable/.
     * A local flock file still serializes concurrent requests within one
     * container; it carries no quota state, so losing it is harmless.
     * If Sheets is unreachable the exception propagates and the API fails
     * closed (503), so a Google outage can never become a quota bypass.
     */
    public static function checkHourly(string $scope, string $token, int $max): bool
    {
        return self::checkWindow($scope, $token, $max, 3600);
    }

    public static function checkWindow(string $scope, string $token, int $max, int $windowSeconds): bool
    {
        return self::mutate($scope, $token, $max, $windowSeconds);
    }

    /** Count in-window hits without recording one. */
    public static function hitCount(string $scope, string $token, int $windowSeconds): int
    {
        if ($token === '') return 0;
        $key = self::digest($scope, $token);
        $rows = Sheets::read(Sheets::sheetName('rateLimits'), 'A2:C');
        $now = time();
        foreach ($rows as $row) {
            if ((string) ($row[0] ?? '') !== $key) continue;
            $hits = self::parseHits((string) ($row[2] ?? ''));
            return count(array_filter($hits, static fn(int $t): bool => $now - $t < $windowSeconds));
        }
        return 0;
    }

    /** Record a hit with no rejection cap (window-trimmed), e.g. admin failures. */
    public static function recordHit(string $scope, string $token, int $windowSeconds): void
    {
        if ($token === '') return;
        self::mutate($scope, $token, PHP_INT_MAX, $windowSeconds);
    }

    private static function mutate(string $scope, string $token, int $max, int $windowSeconds): bool
    {
        if ($token === '' || $max < 1 || $windowSeconds < 1) return false;
        $key = self::digest($scope, $token);
        $handle = self::lock($key);
        if ($handle === false) return false;

        try {
            $sheet = Sheets::sheetName('rateLimits');
            if (!self::$tabChecked) {
                Sheets::ensureSheet($sheet, ['rl_key', 'scope', 'hits']);
                self::$tabChecked = true;
            }
            $rows = Sheets::read($sheet, 'A2:C');
            $now = time();
            $rowNumber = 0;
            $hits = [];
            foreach ($rows as $index => $row) {
                if ((string) ($row[0] ?? '') !== $key) continue;
                $rowNumber = (int) $index + 2;
                $hits = self::parseHits((string) ($row[2] ?? ''));
                break;
            }

            $windowHits = array_values(array_filter($hits, static fn(int $t): bool => $now - $t < $windowSeconds));
            if (count($windowHits) >= $max) return false;

            $windowHits[] = $now;
            if (count($windowHits) > 50) $windowHits = array_slice($windowHits, -50);
            $csv = implode(',', $windowHits);
            if ($rowNumber > 0) {
                Sheets::setCell($sheet, 'C' . $rowNumber, $csv, 'RAW');
            } else {
                Sheets::append($sheet, [$key, $scope, $csv], 'RAW');
            }
            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Scope => window seconds for every limiter the app uses. */
    public static function knownWindows(): array
    {
        return [
            'otp_24h' => 86400,
            'submit' => 3600,
            'report' => 3600,
            'urlcheck' => 3600,
            'suggestion' => 3600,
            'admin_fail' => 3600,
            'vote' => 3600,
        ];
    }

    /** Prune expired hits across all scopes in one pass; returns rows deleted. */
    public static function cleanupAll(): int
    {
        $handle = self::lock('cleanup');
        if ($handle === false) return 0;

        try {
            $sheet = Sheets::sheetName('rateLimits');
            $rows = Sheets::read($sheet, 'A2:C');
            $windows = self::knownWindows();
            $now = time();

            $removed = 0;
            $survivors = [];
            $changed = false;
            foreach ($rows as $row) {
                $key = (string) ($row[0] ?? '');
                $scope = (string) ($row[1] ?? '');
                if ($key === '') continue;
                $window = $windows[$scope] ?? 86400;
                $hits = self::parseHits((string) ($row[2] ?? ''));
                $valid = array_values(array_filter($hits, static fn(int $t): bool => $now - $t < $window));
                if ($valid === []) {
                    $removed++;
                    continue;
                }
                if (count($valid) !== count($hits)) $changed = true;
                $survivors[] = [$key, $scope, implode(',', $valid)];
            }

            if ($removed > 0 || $changed) {
                if ($rows !== []) {
                    Sheets::clear($sheet, 'A2:C');
                }
                foreach ($survivors as $row) {
                    Sheets::append($sheet, $row, 'RAW');
                }
            }
            return $removed;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function digest(string $scope, string $token): string
    {
        return hash_hmac('sha256', $scope . '|' . $token, (string) Config::get('encryption_key'));
    }

    /** @return int[] */
    private static function parseHits(string $csv): array
    {
        // Plausible-epoch filter also neutralizes any legacy cell that Google
        // reformatted with thousands separators.
        return array_values(array_filter(
            array_map('intval', array_filter(explode(',', $csv), 'strlen')),
            static fn(int $t): bool => $t > 1_500_000_000 && $t < 4_000_000_000
        ));
    }

    /** @return resource|false Single mutex: cleanupAll rewrites the whole tab
     *  by row index, so every reader/writer of Rate_Limits must serialize on it. */
    private static function lock(string $key)
    {
        $dir = (string) Config::get('storage_path');
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        return @fopen($dir . '/rl_global.lock', 'c');
    }
}
