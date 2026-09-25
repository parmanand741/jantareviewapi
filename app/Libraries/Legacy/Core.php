<?php

declare(strict_types=1);

namespace App\Libraries\Legacy;

use CodeIgniter\HTTP\IncomingRequest;
use RuntimeException;

final class Config
{
    private static ?array $data = null;

    public static function load(): void
    {
        $config = config('Review');
        self::$data = [
            'spreadsheet_id' => $config->spreadsheetId,
            'credentials_path' => $config->credentialsPath,
            'encryption_key' => $config->encryptionKey,
            'admin_key' => $config->adminKey,
            'turnstile_secret' => $config->turnstileSecret,
            'allow_local_turnstile_bypass' => $config->allowLocalTurnstileBypass,
            'turnstile_test_mode' => $config->turnstileTestMode,
            'expose_errors' => $config->exposeErrors,
            'otp_from_email' => $config->otpFromEmail,
            'otp_from_name' => $config->otpFromName,
            'mailjet_api_key' => $config->mailjetApiKey,
            'mailjet_secret_key' => $config->mailjetSecretKey,
            'otp_ttl_seconds' => $config->otpTtlSeconds,
            'otp_max_attempts' => $config->otpMaxAttempts,
            'otp_request_per_day' => $config->otpRequestPerDay,
            'allowed_origins' => $config->allowedOrigins,
            'allowed_turnstile_hosts' => $config->allowedTurnstileHosts,
            'sheets' => $config->sheets,
            'unpublish_threshold' => $config->unpublishThreshold,
            'rescue_threshold' => $config->rescueThreshold,
            'report_ceiling' => $config->reportCeiling,
            'vote_cooldown_seconds' => $config->voteCooldownSeconds,
            'report_cooldown_seconds' => $config->reportCooldownSeconds,
            'submit_per_hour' => $config->submitPerHour,
            'report_per_hour' => $config->reportPerHour,
            'vote_per_hour' => $config->votePerHour,
            'link_check_per_hour' => $config->linkCheckPerHour,
            'review_plain_max_len' => $config->reviewPlainMaxLen,
            'review_enc_max_len' => $config->reviewEncMaxLen,
            'product_name_max_len' => $config->productNameMaxLen,
            'report_reason_max_len' => $config->reportReasonMaxLen,
            'grievance_desc_min_len' => $config->grievanceDescMinLen,
            'grievance_desc_max_len' => $config->grievanceDescMaxLen,
            'platform_name' => $config->platformName,
            'jurisdiction' => $config->jurisdiction,
            'retention_days' => $config->retentionDays,
            'grievance_officer' => $config->grievanceOfficer,
            'storage_path' => $config->storagePath,
            'debug' => $config->debug,
        ];
        self::validate();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$data === null) {
            self::load();
        }
        return self::$data[$key] ?? $default;
    }

    private static function validate(): void
    {
        foreach (['spreadsheet_id', 'credentials_path', 'encryption_key', 'admin_key', 'turnstile_secret'] as $key) {
            $value = self::$data[$key] ?? '';
            if (!is_string($value) || $value === '') {
                throw new RuntimeException("Review configuration '{$key}' is missing.");
            }
        }
        if (strlen((string) self::$data['encryption_key']) < 32) {
            throw new RuntimeException('Review encryption key must be at least 32 characters.');
        }
        if ((array) self::$data['allowed_origins'] === []) {
            throw new RuntimeException('At least one allowed origin must be configured.');
        }
        if ((array) self::$data['allowed_turnstile_hosts'] === []) {
            throw new RuntimeException('At least one allowed Turnstile hostname must be configured.');
        }
        $credentials = (string) self::$data['credentials_path'];
        if (!is_file($credentials)) {
            throw new RuntimeException(
                'Google service-account file is unavailable. Place service-account.json outside the public web root or update GOOGLE_APPLICATION_CREDENTIALS. Expected path: '
                . ($credentials !== '' ? $credentials : '(not configured)')
            );
        }
        $public = realpath(FCPATH);
        $credentialRealPath = realpath($credentials);
        if (
            $public !== false
            && $credentialRealPath !== false
            && ($credentialRealPath === $public || str_starts_with($credentialRealPath, $public . DIRECTORY_SEPARATOR))
        ) {
            throw new RuntimeException('Google credentials must not be stored below the public web root.');
        }
    }
}

final class ApiResponseException extends RuntimeException
{
    public function __construct(public readonly array $payload, public readonly int $status = 200)
    {
        parent::__construct((string) ($payload['error'] ?? 'API response'));
    }
}

final class Request
{
    public readonly string $action;
    public readonly array $payload;
    public readonly string $method;
    public readonly string $origin;
    public readonly string $ip;
    public readonly string $submitterToken;

    public function __construct(?IncomingRequest $request = null)
    {
        $request ??= service('request');
        $this->method = strtoupper($request->getMethod());
        $this->origin = (string) $request->getHeaderLine('Origin');
        $this->ip = (string) ($request->getIPAddress() ?: '0.0.0.0');
        if ($this->method === 'POST') {
            $raw = (string) $request->getBody();
            if (strlen($raw) > 100 * 1024) {
                Response::error('Request too large.', 413);
            }
            $decoded = json_decode($raw, true);
            $this->payload = is_array($decoded) ? $decoded : [];
            if (!isset($this->payload['adminKey'])) {
                $headerKey = $request->getHeaderLine('X-Admin-Key');
                if ($headerKey !== '') {
                    $this->payload['adminKey'] = $headerKey;
                }
            }
            $this->action = (string) ($this->payload['action'] ?? $request->getGet('action') ?? '');
        } else {
            $this->payload = $request->getGet();
            $this->action = (string) ($request->getGet('action') ?? '');
        }
        $salt = gmdate('Y-m-d');
        $ua = $request->getHeaderLine('User-Agent');
        $this->submitterToken = substr(hash('sha256', $salt . '|' . $this->ip . '|' . $ua), 0, 20);
    }
}

final class Response
{
    public static function json(array $data, int $status = 200): never
    {
        throw new ApiResponseException($data, $status);
    }

    public static function error(string $message, int $status = 400): never
    {
        self::json(['success' => false, 'error' => $message], $status);
    }

    public static function ok(array $data = []): never
    {
        self::json(['success' => true] + $data);
    }
}

final class Admin
{
    private const MAX_FAILURES_PER_HOUR = 10;
    private const WINDOW_SECONDS = 3600;

    public static function login(?string $key): bool
    {
        if (self::isRateLimited()) {
            return false;
        }
        $expected = (string) Config::get('admin_key', '');
        $given = (string) ($key ?? '');
        if ($given === '' || !hash_equals($expected, $given)) {
            self::recordFailure();
            return false;
        }
        $session = service('session');
        $session->regenerate(true);
        $session->set('review_admin_authenticated', true);
        return true;
    }

    public static function logout(): void
    {
        $session = service('session');
        $session->remove('review_admin_authenticated');
        $session->regenerate(true);
    }

    public static function isAuthenticated(): bool
    {
        return service('session')->get('review_admin_authenticated') === true;
    }

    public static function require(?string $key = null): void
    {
        if (self::isAuthenticated()) {
            return;
        }
        Response::error('Unauthorized.', 401);
    }

    private static function recordFailure(): void
    {
        RateLimit::recordHit('admin_fail', self::ipToken(), self::WINDOW_SECONDS);
    }

    private static function isRateLimited(): bool
    {
        return RateLimit::hitCount('admin_fail', self::ipToken(), self::WINDOW_SECONDS) >= self::MAX_FAILURES_PER_HOUR;
    }

    private static function ipToken(): string
    {
        return (string) service('request')->getIPAddress();
    }
}
