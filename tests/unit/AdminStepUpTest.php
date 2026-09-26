<?php

declare(strict_types=1);

use App\Libraries\Legacy\Admin;
use App\Libraries\Legacy\ApiResponseException;
use App\Libraries\Legacy\Config;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Step-up is the only thing standing between a hijacked admin session and a
 * bulk delete, and a wrong key here never reaches the datastore, so both
 * directions are safe to assert in a test.
 *
 * @internal
 */
final class AdminStepUpTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $session = service('session');
        $session->set('review_admin_authenticated', true);
        $session->set('review_admin_at', time());
    }

    public function testAcceptedKeyIsTheOneConfigured(): void
    {
        $this->assertNotEmpty(Config::get('admin_key'), 'admin_key must be configured for step-up to work at all.');
    }

    public function testCorrectKeyPassesWithoutThrowing(): void
    {
        Admin::requireKey((string) Config::get('admin_key'));
        $this->assertTrue(true);
    }

    public function testMissingKeyIsRefused(): void
    {
        $this->expectException(ApiResponseException::class);
        Admin::requireKey(null);
    }

    public function testWrongKeyIsRefused(): void
    {
        $this->expectException(ApiResponseException::class);
        Admin::requireKey(str_repeat('x', 64));
    }

    public function testSessionAloneIsNotEnough(): void
    {
        service('session')->remove('review_admin_authenticated');
        $this->expectException(ApiResponseException::class);
        Admin::requireKey((string) Config::get('admin_key'));
    }
}
