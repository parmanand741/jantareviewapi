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
        service('session')->set('review_otp_verified', [
            'challenge_hash' => self::digest($challengeId),
            'verification_hash' => self::digest($verificationToken),
            'session_hash' => $record['session_hash'],
            'expires_at' => $record['expires_at'],
        ]);
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
        service('session')->remove('review_otp_verified');
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
            'quotaRecords' => RateLimit::cleanupWindow('otp_24h', 86400),
        ];
    }

    private static function send(string $to, string $code): bool
    {
        $from = (string) Config::get('otp_from_email', '');
        $apiKey = (string) Config::get('brevo_api_key', '');
        if ($from === '' || $apiKey === '') {
            log_message('critical', 'OTP mail is unconfigured: OTP_FROM_EMAIL or BREVO_API_KEY is empty.');
            return false;
        }

        $minutes = max(1, (int) round(((int) Config::get('otp_ttl_seconds', 600)) / 60));

        try {
            $response = service('curlrequest')->post((string) Config::get('brevo_api_url'), [
                'timeout' => 15,
                'headers' => [
                    'api-key'      => $apiKey,
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                'json' => [
                    'sender'  => ['name' => (string) Config::get('otp_from_name', 'JantaReview'), 'email' => $from],
                    'to'      => [['email' => $to]],
                    'subject' => 'Your JantaReview verification code',
                    'textContent' => "Your verification code is {$code}.\n\n"
                        . "This code expires in {$minutes} minutes. If you did not request it, you can ignore this email.",
                ],
            ]);
        } catch (\Throwable $e) {
            log_message('critical', 'Brevo OTP request failed: ' . $e->getMessage());
            return false;
        }

        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) return true;

        // Brevo's body names the rejection reason (invalid key, unverified sender, quota).
        log_message('critical', 'Brevo rejected the OTP email: HTTP ' . $status . ' ' . (string) $response->getBody());
        return false;
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
