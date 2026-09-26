<?php

declare(strict_types=1);

namespace App\Libraries\Legacy;

/**
 * Transactional mail through Mailjet's HTTPS API.
 *
 * PHP's mail() is unusable here: Render's containers have no MTA, and even on
 * WAMP a bare "From:" header makes SPF/DKIM fail so every message lands in
 * Spam. Everything goes out through the same authenticated sender the OTP mail
 * already uses.
 */
final class Mailer
{
    private const API = 'https://api.mailjet.com/v3.1/send';

    public static function configured(): bool
    {
        return self::sender() !== ''
            && (string) Config::get('mailjet_api_key', '') !== ''
            && (string) Config::get('mailjet_secret_key', '') !== '';
    }

    public static function send(string $to, string $subject, string $text): bool
    {
        if ($to === '' || !self::configured()) {
            return false;
        }

        $payload = [
            'Messages' => [[
                'From' => [
                    'Email' => self::sender(),
                    'Name' => (string) Config::get('otp_from_name', 'JantaReview'),
                ],
                'To' => [['Email' => $to]],
                'Subject' => $subject,
                'TextPart' => $text,
            ]],
        ];

        $curl = \Config\Services::curlrequest();
        try {
            $res = $curl->post(self::API, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Basic ' . base64_encode(
                        (string) Config::get('mailjet_api_key') . ':' . (string) Config::get('mailjet_secret_key')
                    ),
                ],
                'body' => json_encode($payload),
                'timeout' => 15,
                'http_errors' => false,
                // WAMP PHP ships without curl.cainfo; reuse the same CA discovery
                // as Sheets. null on Render/Linux means the system store is used.
                'verify' => Sheets::findCaBundle() ?? true,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Mailjet request failed: {message}', ['message' => $e->getMessage()]);
            return false;
        }

        if ($res->getStatusCode() !== 200) {
            log_message('error', 'Mailjet send failed: HTTP {code} {body}', [
                'code' => $res->getStatusCode(),
                'body' => (string) $res->getBody(),
            ]);
            return false;
        }
        $body = json_decode((string) $res->getBody(), true);
        return (bool) ($body['Messages'][0]['Status'] ?? false);
    }

    private static function sender(): string
    {
        return (string) Config::get('otp_from_email', '');
    }
}
