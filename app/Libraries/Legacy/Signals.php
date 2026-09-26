<?php

declare(strict_types=1);

namespace App\Libraries\Legacy;

/**
 * Spam and duplication signals computed at submission time.
 *
 * Everything here is advisory: the codes are recorded on the entry so a human
 * can see why it looks odd. The one case that stops a post outright is text
 * that is byte-for-byte a copy of something already on the site, which has no
 * legitimate reading.
 */
final class Signals
{
    /** Bodies that carry a copy of the review text are the classic vector. */
    private const SHORTENERS = [
        'bit.ly', 'tinyurl.com', 't.ly', 'cutt.ly', 'ow.ly', 'is.gd', 'rb.gy',
        'buff.ly', 'shorturl.at', 'rebrand.ly', 't.co', 'wa.me', 'chat.whatsapp.com',
        'tiny.cc', 'v.gd', 'so.go', 'urlsin.com', 'freefireguard.com',
    ];

    /**
     * Case-, punctuation- and whitespace-insensitive form of a review, so the
     * same text pasted with a different capitalisation still matches itself.
     */
    public static function normalize(string $text): string
    {
        $t = mb_strtolower($text, 'UTF-8');
        $t = preg_replace('~[\p{P}\p{S}\p{M}\p{C}]+~u', ' ', $t) ?? $t;
        $t = preg_replace('~\s+~u', ' ', $t) ?? $t;
        return trim($t);
    }

    public static function textHash(string $text): string
    {
        $normalized = self::normalize($text);
        if ($normalized === '') return '';
        return hash_hmac('sha256', $normalized, (string) Config::get('encryption_key'));
    }

    /**
     * Codes for this text and link. Names are stable: they are stored on the
     * entry and shown to admins, so renaming one is a data migration.
     *
     * @return string[]
     */
    public static function flags(string $text, string $productUrl, string $productName): array
    {
        $flags = [];
        $lower = mb_strtolower($text, 'UTF-8');

        foreach (self::SHORTENERS as $domain) {
            if (str_contains($lower, $domain . '/')) { $flags[] = 'short_link'; break; }
        }

        if (preg_match('~https?://|www\.~i', $text) === 1) {
            $flags[] = 'body_link';
        }
        // Indian mobile formats plus anything that reads like a dial-out string.
        if (preg_match('~(?:\+?91[\s-]?)?[6-9]\d{9}\b|wa\.me/\d|whatsapp\.com/\d|int\.me/\d|t\.me/\d~i', $text) === 1) {
            $flags[] = 'contact_details';
        }

        if (preg_match('~(\S+)(?:\s+\1){4,}~iu', $text) === 1) {
            $flags[] = 'repeated_phrase';
        }
        if (preg_match('~(\S{25,})~', $text) === 1) {
            $flags[] = 'run_on_string';
        }

        $letters = preg_split('//u', preg_replace('~\PL~u', '', $text) ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($letters) >= 20) {
            $upper = count(array_filter($letters, static fn(string $c): bool => $c !== mb_strtolower($c, 'UTF-8')));
            if ($upper / count($letters) > 0.6) $flags[] = 'shouting';
        }

        // Emoji live outside the BMP blocks a normal filter keeps.
        if (preg_match('~[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]~u', $text) === 1) {
            $matches = [];
            if (preg_match_all('~[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]~u', $text, $matches) > 6) {
                $flags[] = 'emoji_heavy';
            }
        }

        if ($productName !== '' && mb_strtolower($productName, 'UTF-8') === $lower && mb_strlen($lower) > 3) {
            $flags[] = 'text_equals_product';
        }
        if ($productUrl !== '' && mb_strtolower($productUrl, 'UTF-8') === $lower) {
            $flags[] = 'text_equals_url';
        }

        return array_values(array_unique($flags));
    }

    /**
     * Rows whose text was written by someone else, newest first.
     *
     * @param array<int, array<int, mixed>> $rows
     */
    public static function duplicateOf(array $rows, string $hash, string $selfToken): array
    {
        if ($hash === '') return [];
        $window = time() - (int) Config::get('dedupe_window_seconds', 86400);

        foreach (array_reverse($rows) as $r) {
            $id = (string) ($r[0] ?? '');
            if ($id === '' || (string) ($r[13] ?? '') !== '') continue;  // replies are not copies
            if ((string) ($r[15] ?? '') === $selfToken) continue;         // same device, new thought
            if ((string) ($r[19] ?? '') !== $hash) continue;
            $at = strtotime((string) ($r[1] ?? ''));
            if ($at === false || $at < $window) continue;
            return ['id' => $id];
        }
        return [];
    }

    /**
     * How many entries already carry this exact link. A shared link is normal
     * — it is what the site is for — so this is recorded, never enforced.
     */
    public static function linkSiblings(array $rows, string $productUrl): int
    {
        if ($productUrl === '') return 0;
        $count = 0;
        foreach ($rows as $r) {
            if ((string) ($r[0] ?? '') !== '' && (string) ($r[2] ?? '') === $productUrl) $count++;
        }
        return $count;
    }
}
