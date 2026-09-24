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
    protected $description = 'Deletes expired OTP challenges and prunes expired rows from the persistent Rate_Limits sheet.';

    public function run(array $params)
    {
        try {
            Config::load();
            $removed = Otp::cleanupExpired();
            CLI::write('Expired OTP challenges removed: ' . $removed['otpChallenges']);
            CLI::write('Expired rate-limit rows removed: ' . $removed['quotaRecords']);
            return EXIT_SUCCESS;
        } catch (\Throwable $e) {
            CLI::error('OTP cleanup failed: ' . $e->getMessage());
            return EXIT_ERROR;
        }
    }
}
