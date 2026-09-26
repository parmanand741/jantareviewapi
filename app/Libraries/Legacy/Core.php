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
            'identity_pepper' => $config->identityPepper !== '' ? $config->identityPepper : $config->encryptionKey,
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
            'vote_user_cooldown_seconds' => $config->voteUserCooldownSeconds,
            'report_tier_free' => $config->reportTierFree,
            'report_tier_slow' => $config->reportTierSlow,
            'report_tier_slow_seconds' => $config->reportTierSlowSeconds,
            'rescue_hysteresis_step' => $config->rescueHysteresisStep,
            'read_per_minute' => $config->readPerMinute,
            'otp_per_ip_per_day' => $config->otpPerIpPerDay,
            'ticket_min_age_seconds' => $config->ticketMinAgeSeconds,
            'quarantine_seconds' => $config->quarantineSeconds,
            'dedupe_window_seconds' => $config->dedupeWindowSeconds,
            'global_caps' => $config->globalCaps,
            'admin_allowed_ips' => $config->adminAllowedIps,
            'admin_ttl_seconds' => $config->adminTtlSeconds,
            'grievance_notify_enabled' => $config->grievanceNotifyEnabled,
            'review_plain_max_len' => $config->reviewPlainMaxLen,
            'review_enc_max_len' => $config->reviewEncMaxLen,
            'product_name_max_len' => $config->productNameMaxLen,
            'report_reason_max_len' => $config->reportReasonMaxLen,
            'grievance_desc_min_len' => $config->grievanceDescMinLen,
            'grievance_desc_max_len' => $config->grievanceDescMaxLen,
            'grievance_per_hour' => $config->grievancePerHour,
            'platform_name' => $config->platformName,
            'jurisdiction' => $config->jurisdiction,
            'retention_days' => $config->retentionDays,
            'audit_retention_days' => $config->auditRetentionDays,
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
        $this->ip = self::resolveIp($request);
        if ($this->method === 'POST') {
            $raw = (string) $request->getBody();
            if (strlen($raw) > 100 * 1024) {
                Response::error('Request too large.', 413);
            }
            $decoded = json_decode($raw, true);
            $this->payload = is_array($decoded) ? $decoded : [];
            $this->action = (string) ($this->payload['action'] ?? $request->getGet('action') ?? '');
        } else {
            $this->payload = $request->getGet();
            $this->action = (string) ($request->getGet('action') ?? '');
        }
        $this->submitterToken = self::identity($this->ip);
    }

    /**
     * Pseudonymous per-visitor identifier. Keyed with a server-side pepper so
     * nobody can mint identities by editing their User-Agent or rotating a
     * proxy, and stable across days so "this user already did X" is checkable.
     */
    public static function identity(string $ip): string
    {
        return substr(
            hash_hmac('sha256', 'id|' . $ip, (string) Config::get('identity_pepper')),
            0,
            20
        );
    }

    /**
     * Client address. Config\App::$proxyIPs makes CI4 read the *leftmost*
     * X-Forwarded-For entry, which is the one a client can forge, so the edge
     * headers that proxies overwrite are preferred and the chain is scanned
     * right-to-left instead.
     *
     * Only headers Cloudflare is known to set and strip are read here. A
     * request that arrives with none of them falls back to REMOTE_ADDR, so a
     * caller that skips the edge cannot choose its own identity.
     */
    public static function resolveIp(IncomingRequest $request): string
    {
        foreach (['CF-Connecting-IP', 'True-Client-IP'] as $header) {
            $value = trim($request->getHeaderLine($header));
            if (filter_var($value, FILTER_VALIDATE_IP)) {
                return $value;
            }
        }

        $remote = (string) $request->getServer('REMOTE_ADDR');
        if (self::isTrustedProxy($remote)) {
            $chain = array_map('trim', explode(',', $request->getHeaderLine('X-Forwarded-For')));
            foreach (array_reverse($chain) as $candidate) {
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }

        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    }

    private static function isTrustedProxy(string $ip): bool
    {
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        foreach (array_keys((array) config('App')->proxyIPs) as $proxy) {
            $proxy = (string) $proxy;
            if ($proxy === $ip) {
                return true;
            }
            if (str_contains($proxy, '/') && self::inCidr($ip, $proxy)) {
                return true;
            }
        }
        return false;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);
        $ipLong = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? ip2long($ip) : false;
        $netLong = filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? ip2long($subnet) : false;
        if ($ipLong === false || $netLong === false || (int) $bits < 0 || (int) $bits > 32) {
            return false;
        }
        $mask = (int) $bits === 0 ? 0 : (-1 << (32 - (int) $bits));
        return ($ipLong & $mask) === ($netLong & $mask);
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
    private const MAX_GLOBAL_ATTEMPTS_PER_HOUR = 20;
    private const WINDOW_SECONDS = 3600;

    public static function login(?string $key): bool
    {
        self::guardIp();
        $expected = (string) Config::get('admin_key', '');
        $given = (string) ($key ?? '');
        if ($given !== '' && $expected !== '' && hash_equals($expected, $given)) {
            $session = service('session');
            $session->regenerate(true);
            $session->set('review_admin_authenticated', true);
            $session->set('review_admin_at', time());
            return true;
        }

        // The shared budget bounds guesses per hour, so it is spent only on a
        // wrong key: a distributed guesser never trips the per-address cap, and
        // refusing the correct key over it would hand them a lockout for free.
        if (!RateLimit::checkLocal('global:admin_login', self::MAX_GLOBAL_ATTEMPTS_PER_HOUR, self::WINDOW_SECONDS)) {
            return false;
        }
        if (self::isRateLimited()) {
            return false;
        }
        self::recordFailure();
        return false;
    }

    public static function logout(): void
    {
        $session = service('session');
        $session->remove('review_admin_authenticated');
        $session->remove('review_admin_at');
        $session->regenerate(true);
    }

    public static function isAuthenticated(): bool
    {
        $session = service('session');
        if ($session->get('review_admin_authenticated') !== true) {
            return false;
        }
        // An idle console should not stay open forever in a browser tab.
        if (time() - (int) $session->get('review_admin_at') > (int) Config::get('admin_ttl_seconds', 1800)) {
            self::logout();
            return false;
        }
        $session->set('review_admin_at', time());
        return true;
    }

    public static function require(): void
    {
        self::guardIp();
        if (self::isAuthenticated()) {
            return;
        }
        Response::error('Unauthorized.', 401);
    }

    /**
     * Step-up for destructive actions: the session alone is not enough, the
     * key has to be presented again in this request.
     */
    public static function requireKey(mixed $key): void
    {
        self::require();
        $expected = (string) Config::get('admin_key', '');
        if ($expected === '' || !hash_equals($expected, (string) ($key ?? ''))) {
            Response::error('Re-authentication required for this action.', 401);
        }
    }

    /** Optional allowlist; empty means the admin console stays reachable. */
    private static function guardIp(): void
    {
        $allowed = array_filter(array_map('trim', (array) Config::get('admin_allowed_ips', [])));
        if ($allowed === []) {
            return;
        }
        $ip = self::ipToken();
        foreach ($allowed as $entry) {
            if ($entry === $ip) return;
            if (str_contains($entry, '/') && Request::inCidr($ip, $entry)) return;
        }
        Response::error('Unauthorized.', 403);
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
        return Request::resolveIp(service('request'));
    }
}
