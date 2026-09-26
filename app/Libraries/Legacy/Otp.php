<?php

declare(strict_types=1);

namespace App\Libraries\Legacy;

final class Otp
{
    private const CHALLENGE_BYTES = 32;
    private const TOKEN_BYTES = 32;

    public static function request(Request $req, string $email): never
    {
        $email = Validator::email($email);
        if ($email === '') Response::error('Please provide a valid email address.');

        // This endpoint mails an arbitrary address, so it is the natural target
        // for a mail-bombing script. It used to have no bot gate at all.
        $ts = Turnstile::verify((string) ($req->payload['turnstileToken'] ?? ''), 'otp', $req->ip);
        if (!$ts['ok']) Response::error('Bot protection failed (' . $ts['reason'] . ').');

        if (!RateLimit::checkLocal('global:otp', (int) (Config::get('global_caps', [])['otp'] ?? 0), 3600)) {
            Response::error('The site is busy right now. Please try again in a few minutes.', 503);
        }
        if (!RateLimit::checkLocal('otp_ip|' . $req->ip, (int) Config::get('otp_per_ip_per_day', 20), 86400)) {
            Response::error('Too many verification requests from your address. Please try again tomorrow.', 429);
        }

        self::removeExpired();
        service('session')->set('review_otp_nonce', bin2hex(random_bytes(16)));
        $emailHash = self::digest($email);
        $quotaKey = $emailHash;
        if (!RateLimit::checkWindow('otp_24h', $quotaKey, (int) Config::get('otp_request_per_day', 3), 86400)) {
            Response::error('You have reached the limit of 3 OTP requests in 24 hours.', 429);
        }

        $challengeId = bin2hex(random_bytes(self::CHALLENGE_BYTES));
        $code = (string) random_int(100000, 999999);
        $record = [
            'email_hash' => $emailHash,
            'otp_hash' => self::digest($code),
            'expires_at' => time() + max(60, (int) Config::get('otp_ttl_seconds', 600)),
            'attempts' => 0,
            'verified' => false,
            'session_hash' => self::sessionBinding($req),
        ];
        self::write($challengeId, $record);

        if (!self::send($email, $code)) {
            self::delete($challengeId);
            Response::error('We could not send the verification code. Please try again later.', 503);
        }

        Response::ok([
            'message' => 'A verification code was sent if the address can receive mail.',
            'challengeId' => $challengeId,
            'expiresIn' => (int) Config::get('otp_ttl_seconds', 600),
        ]);
    }

    public static function verify(Request $req, string $challengeId, string $code): never
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $challengeId) || !preg_match('/^\d{6}$/', $code)) {
            Response::error('Invalid verification request.');
        }
        $record = self::read($challengeId);
        if ($record === null || ($record['session_hash'] ?? '') !== self::sessionBinding($req)) {
            Response::error('Verification request expired or is invalid.', 401);
        }
        if ((int) ($record['expires_at'] ?? 0) < time()) {
            self::delete($challengeId);
            Response::error('Verification code expired. Please request a new code.', 410);
        }
        if ((int) ($record['attempts'] ?? 0) >= (int) Config::get('otp_max_attempts', 5)) {
            self::delete($challengeId);
            Response::error('Too many incorrect attempts. Please request a new code.', 429);
        }
        $record['attempts'] = (int) ($record['attempts'] ?? 0) + 1;
        if (!hash_equals((string) ($record['otp_hash'] ?? ''), self::digest($code))) {
            self::write($challengeId, $record);
            Response::error('Incorrect verification code.', 401);
        }

        $verificationToken = bin2hex(random_bytes(self::TOKEN_BYTES));
        $session = service('session');
        $session->set('review_otp_verified', [
            'challenge_hash' => self::digest($challengeId),
            'verification_hash' => self::digest($verificationToken),
            'session_hash' => $record['session_hash'],
            'expires_at' => $record['expires_at'],
        ]);
        // Server-side proof of how long this browser spent on the OTP step.
        // submitReview refuses to run until the floor has passed, so a script
        // that solves the whole flow back-to-back cannot post.
        $session->set('review_otp_verified_at', time());
        self::delete($challengeId);
        Response::ok(['message' => 'Email verified.', 'verificationToken' => $verificationToken]);
    }

    public static function consume(Request $req, string $challengeId, string $token): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $challengeId) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            Response::error('Email verification is required.');
        }
        $record = service('session')->get('review_otp_verified');
        $valid = is_array($record)
            && hash_equals((string) ($record['challenge_hash'] ?? ''), self::digest($challengeId))
            && ($record['session_hash'] ?? '') === self::sessionBinding($req)
            && (int) ($record['expires_at'] ?? 0) >= time()
            && hash_equals((string) ($record['verification_hash'] ?? ''), self::digest($token));
        if (!$valid) Response::error('Email verification is required or has expired.', 401);

        $session = service('session');
        $floor = (int) Config::get('ticket_min_age_seconds', 3);
        if ($floor > 0 && time() - (int) $session->get('review_otp_verified_at') < $floor) {
            // The verification stays valid: a real reviewer who is simply quick
            // can retry a moment later, while a script cannot post at all.
            Response::error('Please wait a moment before publishing.', 429);
        }
        $session->remove('review_otp_verified');
        $session->remove('review_otp_verified_at');
    }

    public static function cleanupExpired(): array
    {
        $now = time();
        $removedChallenges = 0;
        $dir = (string) Config::get('storage_path') . DIRECTORY_SEPARATOR . 'otp';

        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
            $raw = @file_get_contents($path);
            $record = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($record) || (int) ($record['expires_at'] ?? 0) < $now) {
                if (@unlink($path)) $removedChallenges++;
            }
        }

        return [
            'otpChallenges' => $removedChallenges,
            'quotaRecords' => RateLimit::cleanupAll(),
        ];
    }

    private static function send(string $to, string $code): bool
    {
        return Mailer::send(
            $to,
            'Your JantaReview verification code',
            "Your verification code is {$code}.\n\nThis code expires in 10 minutes. If you did not request it, you can ignore this email."
        );
    }

    private static function sessionBinding(Request $req): string
    {
        $nonce = (string) service('session')->get('review_otp_nonce');
        return self::digest($nonce . '|' . $req->origin . '|' . service('request')->getHeaderLine('User-Agent'));
    }

    private static function digest(string $value): string
    {
        return hash_hmac('sha256', $value, (string) Config::get('encryption_key'));
    }

    private static function path(string $id): string
    {
        $dir = (string) Config::get('storage_path') . DIRECTORY_SEPARATOR . 'otp';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('OTP storage is unavailable.');
        }
        return $dir . DIRECTORY_SEPARATOR . hash('sha256', $id) . '.json';
    }

    private static function write(string $id, array $record): void
    {
        $path = self::path($id);
        $tmp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (file_put_contents($tmp, json_encode($record, JSON_THROW_ON_ERROR), LOCK_EX) === false
            || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('OTP storage is unavailable.');
        }
    }

    private static function read(string $id): ?array
    {
        $path = self::path($id);
        if (!is_file($path)) return null;
        $raw = file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($data) ? $data : null;
    }

    private static function delete(string $id): void
    {
        @unlink(self::path($id));
    }

    private static function removeExpired(): void
    {
        self::cleanupExpired();
    }
}
