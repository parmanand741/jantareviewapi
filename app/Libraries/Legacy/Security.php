<?php

declare(strict_types=1);

namespace App\Libraries\Legacy;

final class Turnstile
{
    public static function verify(?string $token, string $expectedAction = ''): array
    {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ((bool) Config::get('allow_local_turnstile_bypass', false)
            && in_array(strtok($host, ':'), ['localhost', '127.0.0.1', 'reviews.local'], true)) {
            return ['ok' => true, 'reason' => 'local-debug'];
        }

        if (!$token || !is_string($token)) {
            return ['ok' => false, 'reason' => 'missing-token'];
        }

        $secret = (string) Config::get('turnstile_secret');
        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['secret' => $secret, 'response' => $token]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        ]);
        $caBundle = (string) ini_get('curl.cainfo');
        if ($caBundle === '') {
            $caBundle = (string) ini_get('openssl.cafile');
        }
        if (is_file($caBundle)) {
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

        $host = strtolower((string) ($r['hostname'] ?? ''));
        $allowed = array_map('strtolower', (array) Config::get('allowed_turnstile_hosts', []));
        if ($host !== '' && $allowed && !in_array($host, $allowed, true)) {
            return ['ok' => false, 'reason' => 'hostname-not-allowed:' . $host];
        }

        $isDevelopmentTestToken = (bool) Config::get('turnstile_test_mode', false)
            && ($r['action'] ?? '') === 'test';
        if ($expectedAction !== '' && !empty($r['action']) && $r['action'] !== $expectedAction && !$isDevelopmentTestToken) {
            return ['ok' => false, 'reason' => 'action-mismatch'];
        }

        return ['ok' => true, 'reason' => ''];
    }
}

final class RateLimit
{
    public static function checkHourly(string $scope, string $token, int $max): bool
    {
        return self::checkWindow($scope, $token, $max, 3600);
    }

    public static function checkWindow(string $scope, string $token, int $max, int $windowSeconds): bool
    {
        if ($token === '' || $max < 1 || $windowSeconds < 1) return false;
        $file = self::file($scope, $token);
        $handle = @fopen($file, 'c+');
        if ($handle === false) return false;

        try {
            if (!flock($handle, LOCK_EX)) return false;
            $now = time();
            rewind($handle);
            $raw = stream_get_contents($handle);
            $hits = array_values(array_filter(
                array_map('intval', array_filter(explode("\n", (string) $raw), 'strlen')),
                static fn(int $t): bool => $now - $t < $windowSeconds
            ));
            if (count($hits) >= $max) return false;

            $hits[] = $now;
            ftruncate($handle, 0);
            rewind($handle);
            if (fwrite($handle, implode("\n", $hits)) === false) return false;
            fflush($handle);
            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public static function cleanupWindow(string $scope, int $windowSeconds): int
    {
        $dir = (string) Config::get('storage_path');
        $pattern = $dir . DIRECTORY_SEPARATOR . 'rl_'
            . preg_replace('/[^a-z0-9_]/i', '', $scope) . '_*.log';
        $removed = 0;
        $cutoff = time() - $windowSeconds;

        foreach (glob($pattern) ?: [] as $file) {
            $raw = @file_get_contents($file);
            $hits = array_map('intval', array_filter(explode("\n", (string) $raw), 'strlen'));
            $valid = array_values(array_filter($hits, static fn(int $hit): bool => $hit >= $cutoff));
            if ($valid === []) {
                if (@unlink($file)) $removed++;
                continue;
            }
            if ($valid !== $hits) {
                @file_put_contents($file, implode("\n", $valid), LOCK_EX);
            }
        }

        return $removed;
    }

    private static function file(string $scope, string $token): string
    {
        $dir = (string) Config::get('storage_path');
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        return $dir . '/rl_' . $scope . '_' . preg_replace('/[^a-z0-9_]/i', '', $token) . '.log';
    }

    private static function read(string $file): array
    {
        if (!is_file($file)) return [];
        $raw = (string) @file_get_contents($file);
        return array_map('intval', array_filter(explode("\n", $raw), 'strlen'));
    }

    private static function write(string $file, array $hits): void
    {
        @file_put_contents($file, implode("\n", $hits), LOCK_EX);
    }
}
