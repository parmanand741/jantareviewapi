<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Runtime configuration for the review API.
 *
 * Secrets and deployment-specific values intentionally come from the
 * environment.  The service-account file must live outside both public roots.
 */
class Review extends BaseConfig
{
    public string $spreadsheetId = '';
    public string $credentialsPath = '';
    public string $encryptionKey = '';
    public string $adminKey = '';
    public string $turnstileSecret = '';
    public bool $allowLocalTurnstileBypass = false;
    public bool $turnstileTestMode = false;
    public bool $exposeErrors = false;
    public string $otpFromEmail = '';
    public string $otpFromName = 'JantaReview';
    public string $otpSmtpHost = '';
    public int $otpSmtpPort = 587;
    public string $otpSmtpUser = '';
    public string $otpSmtpPass = '';
    public string $otpSmtpCrypto = 'tls';
    public int $otpTtlSeconds = 600;
    public int $otpMaxAttempts = 5;
    public int $otpRequestPerDay = 3;
    public array $allowedOrigins = [];
    public array $allowedTurnstileHosts = [];
    public array $sheets = [
        'reviews' => 'Reviews',
        'reports' => 'Reported_Reviews',
        'deleted' => 'Deleted_Reviews',
        'modlog' => 'Mod_Log',
        'grievances' => 'Grievances',
        'anonymousGrievances' => 'Anonymous_Grievances',
        'platforms' => 'Plateforms',
        'suggestions' => 'Suggestions',
    ];
    public int $unpublishThreshold = -15;
    public int $rescueThreshold = -10;
    public int $reportCeiling = 15;
    public int $voteCooldownSeconds = 60;
    public int $reportCooldownSeconds = 3600;
    public int $submitPerHour = 20;
    public int $reportPerHour = 30;
    public int $reviewPlainMaxLen = 500;
    public int $reviewEncMaxLen = 16000;
    public int $productNameMaxLen = 200;
    public int $reportReasonMaxLen = 500;
    public int $grievanceDescMinLen = 20;
    public int $grievanceDescMaxLen = 500;
    public string $platformName = 'JantaReview';
    public string $jurisdiction = 'India';
    public int $retentionDays = 90;
    public array $grievanceOfficer = [];
    public string $storagePath = WRITEPATH . 'review';
    public bool $debug = false;

    public function __construct()
    {
        parent::__construct();

        $this->spreadsheetId = (string) env('REVIEW_SPREADSHEET_ID', '');
        $this->credentialsPath = $this->resolveCredentialsPath(
            (string) env('GOOGLE_APPLICATION_CREDENTIALS', '')
        );
        $this->encryptionKey = (string) env('REVIEW_ENCRYPTION_KEY', '');
        $this->adminKey = (string) env('REVIEW_ADMIN_KEY', '');
        $this->turnstileTestMode = filter_var(env('TURNSTILE_USE_TEST_KEYS', false), FILTER_VALIDATE_BOOLEAN);
        $this->turnstileSecret = $this->turnstileTestMode
            ? (string) env('TURNSTILE_TEST_SECRET', '')
            : (string) env('TURNSTILE_SECRET', '');
        $this->allowLocalTurnstileBypass = filter_var(env('TURNSTILE_BYPASS_FOR_LOCAL_DEV', false), FILTER_VALIDATE_BOOLEAN);
        $this->exposeErrors = filter_var(env('REVIEW_EXPOSE_ERRORS', false), FILTER_VALIDATE_BOOLEAN);
        $this->otpFromEmail = (string) env('OTP_FROM_EMAIL', '');
        $this->otpFromName = (string) env('OTP_FROM_NAME', 'JantaReview');
        $this->otpSmtpHost = (string) env('OTP_SMTP_HOST', '');
        $this->otpSmtpPort = (int) env('OTP_SMTP_PORT', 587);
        $this->otpSmtpUser = (string) env('OTP_SMTP_USER', '');
        $this->otpSmtpPass = (string) env('OTP_SMTP_PASS', '');
        $this->otpSmtpCrypto = strtolower((string) env('OTP_SMTP_CRYPTO', 'tls'));
        $this->otpTtlSeconds = (int) env('OTP_TTL_SECONDS', 600);
        $this->otpMaxAttempts = (int) env('OTP_MAX_ATTEMPTS', 5);
        $this->otpRequestPerDay = (int) env('OTP_REQUESTS_PER_DAY', 3);
        $this->allowedOrigins = $this->csv('REVIEW_ALLOWED_ORIGINS');
        $this->allowedTurnstileHosts = $this->csv('REVIEW_TURNSTILE_HOSTS');
        $this->unpublishThreshold = (int) env('REVIEW_UNPUBLISH_THRESHOLD', -15);
        $this->rescueThreshold = (int) env('REVIEW_RESCUE_THRESHOLD', -10);
        $this->reportCeiling = (int) env('REVIEW_REPORT_CEILING', 15);
        $this->voteCooldownSeconds = (int) env('REVIEW_VOTE_COOLDOWN_SECONDS', 60);
        $this->reportCooldownSeconds = (int) env('REVIEW_REPORT_COOLDOWN_SECONDS', 3600);
        $this->submitPerHour = (int) env('REVIEW_SUBMIT_PER_HOUR', 20);
        $this->reportPerHour = (int) env('REVIEW_REPORT_PER_HOUR', 30);
        $this->reviewPlainMaxLen = (int) env('REVIEW_PLAIN_MAX_LEN', 500);
        $this->reviewEncMaxLen = (int) env('REVIEW_ENCRYPTED_MAX_LEN', 16000);
        $this->productNameMaxLen = (int) env('REVIEW_PRODUCT_NAME_MAX_LEN', 200);
        $this->reportReasonMaxLen = (int) env('REVIEW_REPORT_REASON_MAX_LEN', 500);
        $this->grievanceDescMinLen = (int) env('REVIEW_GRIEVANCE_DESC_MIN_LEN', 20);
        $this->grievanceDescMaxLen = (int) env('REVIEW_GRIEVANCE_DESC_MAX_LEN', 500);
        $this->platformName = (string) env('REVIEW_PLATFORM_NAME', 'JantaReview');
        $this->jurisdiction = (string) env('REVIEW_JURISDICTION', 'India');
        $this->retentionDays = (int) env('REVIEW_RETENTION_DAYS', 90);
        $this->storagePath = (string) env('REVIEW_STORAGE_PATH', WRITEPATH . 'review');
        $this->debug = filter_var(env('CI_DEBUG', false), FILTER_VALIDATE_BOOLEAN);

        $this->grievanceOfficer = [
            'name' => (string) env('GRIEVANCE_OFFICER_NAME', ''),
            'email' => (string) env('GRIEVANCE_OFFICER_EMAIL', ''),
            'phone' => (string) env('GRIEVANCE_OFFICER_PHONE', ''),
            'address' => (string) env('GRIEVANCE_OFFICER_ADDRESS', ''),
            'ack_sla_hours' => (int) env('GRIEVANCE_ACK_SLA_HOURS', 24),
            'resolve_sla_days' => (int) env('GRIEVANCE_RESOLVE_SLA_DAYS', 15),
            'priority_takedown_hours' => (int) env('GRIEVANCE_PRIORITY_TAKEDOWN_HOURS', 36),
        ];
    }

    private function csv(string $key): array
    {
        $value = trim((string) env($key, ''));
        return $value === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    private function resolveCredentialsPath(string $configured): string
    {
        $configured = trim($configured);
        $candidates = [];

        if ($configured !== '') {
            $candidates[] = $configured;
            if (!preg_match('/^[A-Za-z]:[\\\\\\/]/', $configured)) {
                $candidates[] = ROOTPATH . ltrim($configured, '/\\');
            }
        }

        $candidates[] = ROOTPATH . 'config' . DIRECTORY_SEPARATOR . 'service-account.json';
        $candidates[] = WRITEPATH . 'secrets' . DIRECTORY_SEPARATOR . 'service-account.json';

        foreach (array_unique($candidates) as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $configured;
    }
}
