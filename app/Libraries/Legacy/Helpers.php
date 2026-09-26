<?php

declare(strict_types=1);

namespace App\Libraries\Legacy;

use RuntimeException;

final class Crypto
{
    private const V4_PREFIX = 'ENCv4:';
    private const V3_PREFIX = 'ENCv3:';

    /**
     * Encrypt review text with AES-256-GCM.
     * Output format: ENCv3:<base64(iv|tag|ciphertext)>
     */
    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') return '';

        if (str_starts_with($plaintext, 'ENCv2:')
            || str_starts_with($plaintext, self::V3_PREFIX)
            || str_starts_with($plaintext, self::V4_PREFIX)) {
            return $plaintext;
        }

        $key = self::key();
        $iv  = random_bytes(12);
        $tag = '';
        $aad = 'JantaReview:review:v4';
        $ct  = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
        if ($ct === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return self::V4_PREFIX . base64_encode($iv . $tag . $ct);
    }

    /**
     * Decrypt review text. Handles ENCv3 (AES-GCM) and ENCv2 (legacy HMAC-CTR
     * from the Google Apps Script backend — read-only compatibility).
     */
    public static function decrypt(string $blob): string
    {
        if ($blob === '') return '';

        if (str_starts_with($blob, self::V4_PREFIX)) {
            return self::decryptV4(substr($blob, strlen(self::V4_PREFIX)));
        }
        if (str_starts_with($blob, self::V3_PREFIX)) {
            return self::decryptV3(substr($blob, strlen(self::V3_PREFIX)));
        }

        if (str_starts_with($blob, 'ENCv2:')) {
            return self::decryptV2(substr($blob, 6));
        }
        if (str_starts_with($blob, 'ENCv1:')) {
            return '[legacy encrypted content]';
        }
        return $blob;
    }

    private static function decryptV4(string $b64): string
    {
        $raw = base64_decode($b64, true);
        if ($raw === false || strlen($raw) < 28) return '[corrupt encrypted content]';
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct = substr($raw, 28);
        $pt = openssl_decrypt(
            $ct,
            'aes-256-gcm',
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            'JantaReview:review:v4'
        );
        return $pt === false ? '[cannot decrypt]' : $pt;
    }

    private static function decryptV3(string $b64): string
    {
        $raw = base64_decode($b64, true);
        if ($raw === false || strlen($raw) < 28) {
            return '[corrupt encrypted content]';
        }
        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct  = substr($raw, 28);
        $pt = openssl_decrypt($ct, 'aes-256-gcm', self::legacyV3Key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $pt === false ? '[cannot decrypt]' : $pt;
    }

    /**
     * Legacy code.gs format: HMAC-SHA256-CTR with 32-byte keystream blocks
     * driven by counter in a 20-byte message (16-byte IV + 4-byte counter).
     */
    private static function decryptV2(string $payload): string
    {
        $parts = explode(':', $payload, 2);
        if (count($parts) !== 2) return '[corrupt ENCv2]';
        $iv = base64_decode($parts[0], true);
        $ct = base64_decode($parts[1], true);
        if ($iv === false || $ct === false || strlen($iv) !== 16) {
            return '[corrupt ENCv2]';
        }
        $key = hash('sha256', (string) Config::get('encryption_key'), true);
        $out = '';
        $len = strlen($ct);
        for ($i = 0, $counter = 0; $i < $len; $i += 32, $counter++) {
            $msg  = $iv . pack('N', $counter);
            $ks   = hash_hmac('sha256', $msg, $key, true);
            $chunk = substr($ct, $i, 32);
            for ($j = 0; $j < strlen($chunk); $j++) {
                $out .= $chunk[$j] ^ $ks[$j];
            }
        }
        return mb_check_encoding($out, 'UTF-8')
            ? $out
            : '[encrypted content — cannot decrypt with the configured key]';
    }

    private static function key(): string
    {
        return hash_hkdf(
            'sha256',
            (string) Config::get('encryption_key'),
            32,
            'jantareview-review-encryption-v4'
        );
    }

    private static function legacyV3Key(): string
    {
        return hash('sha256', (string) Config::get('encryption_key'), true);
    }

    /**
     * SHA-256 of id|timestamp|text — proves content integrity without revealing it.
     */
    public static function contentHash(string $id, string $timestamp, string $text): string
    {
        return substr(hash('sha256', $id . '|' . $timestamp . '|' . $text), 0, 32);
    }
}

final class Validator
{
    /**
     * Strip dangerous content, normalize unicode, enforce max length.
     */
    public static function sanitize(mixed $input, int $maxLen = 5000): string
    {
        if ($input === null || $input === false) return '';
        $s = (string) $input;
        $s = preg_replace('/<\/?[^>]+(>|$)/u', '', $s) ?? '';
        $s = str_replace(['<', '>'], '', $s);
        $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{2060}\x{180E}]/u', '', $s) ?? '';
        $s = preg_replace('/[\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]/u', ' ', $s) ?? '';
        $s = preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u', '', $s) ?? '';
        $s = trim($s);
        if (mb_strlen($s) > $maxLen) $s = mb_substr($s, 0, $maxLen);
        return $s;
    }

    public static function email(mixed $raw): string
    {
        $v = strtolower(self::sanitize($raw, 254));
        return filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : '';
    }

    /**
     * Validates that a URL is public HTTPS. Rejects localhost, RFC1918, etc.
     */
    public static function url(mixed $raw): string
    {
        $s = self::sanitize($raw, 2048);
        if (!preg_match('~^https://~i', $s)) return '';
        $p = parse_url($s);
        if (!$p || empty($p['host'])) return '';
        $host = strtolower($p['host']);
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) return '';
        if (preg_match('/^(127\.|10\.|192\.168\.|169\.254\.|0\.)/', $host)) return '';
        if (preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $host)) return '';
        if (!preg_match('/\.[a-z]{2,}$/i', $host)) return '';
        // Shops answer on 443 and ProductLink::probe refuses any other port, so
        // a link stored with one would be a link whose target was never checked.
        if (!empty($p['port']) && (int) $p['port'] !== 443) return '';

        // Strip tracking params
        $tracking = [
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_term',
            'utm_content',
            'utm_id',
            'utm_name',
            'utm_reader',
            'utm_vis',
            'utm_cid',
            'ref',
            'ref_',
            'referrer',
            'source',
            '_encoding',
            'fbclid',
            'gclid',
            'gclsrc',
            'dclid',
            'msclkid',
            'mc_cid',
            'mc_eid',
            'yclid',
            'igshid',
            'si',
            'spm',
            'scm',
            'pf_rd_p',
            'pf_rd_r',
            'pd_rd_r',
            'pd_rd_w',
            'pd_rd_wg',
            'content-id',
            'tag',
            'linkcode',
            'creative',
            'creativeasin',
            'ascsubtag',
            'smid',
            'th',
            'psc',
            'sr',
            'qid',
            'srsltid'
        ];
        $keep = [];
        if (!empty($p['query'])) {
            foreach (explode('&', $p['query']) as $pair) {
                if ($pair === '') continue;
                $k = strtolower(explode('=', $pair, 2)[0]);
                if (!in_array($k, $tracking, true)) $keep[] = $pair;
            }
        }
        $q = $keep ? '?' . implode('&', $keep) : '';
        $path = $p['path'] ?? '/';
        return 'https://' . $host . $path . $q;
    }

    public static function stars(mixed $raw): int
    {
        $n = (int) $raw;
        return ($n >= 1 && $n <= 5) ? $n : 0;
    }

    public static function status(mixed $raw): string
    {
        $s = strtolower(trim((string) $raw));
        return in_array($s, ['unpublished', 'quarantine', 'quarantined', 'archived'], true)
            ? 'archived' : 'published';
    }
}

final class Ids
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function make(string $prefix): string
    {
        $s = '';
        for ($i = 0; $i < 6; $i++) {
            $s .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        return $prefix . '-' . $s;
    }
}

final class Time
{
    public static function iso(mixed $v): string
    {
        if ($v === null || $v === '' || $v === false) return '';
        if (is_string($v)) {
            $t = strtotime($v);
            return $t === false ? '' : gmdate('c', $t);
        }
        // Google Sheets returns serial numbers as floats for dates.
        if (is_numeric($v)) {
            $serial = (float) $v;
            // Sheets epoch is 1899-12-30.
            $unix = ($serial - 25569) * 86400;
            return gmdate('c', (int) $unix);
        }
        return '';
    }

    public static function nowIso(): string
    {
        return gmdate('c');
    }
}
