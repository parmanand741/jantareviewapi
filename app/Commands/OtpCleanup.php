<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Legacy\Config;
use App\Libraries\Legacy\Otp;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

final class OtpCleanup extends BaseCommand
{
    protected $group = 'Security';
    protected $name = 'security:otp-cleanup';
    protected $description = 'Deletes expired OTP challenges and 24-hour OTP quota records.';

    public function run(array $params)
    {
        try {
            Config::load();
            $removed = Otp::cleanupExpired();
            CLI::write('Expired OTP challenges removed: ' . $removed['otpChallenges']);
            CLI::write('Expired OTP quota records removed: ' . $removed['quotaRecords']);
            return EXIT_SUCCESS;
        } catch (\Throwable $e) {
            CLI::error('OTP cleanup failed: ' . $e->getMessage());
            return EXIT_ERROR;
        }
    }
}
