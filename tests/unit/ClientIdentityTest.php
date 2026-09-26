<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\Legacy\Request;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;

final class ClientIdentityTest extends CIUnitTestCase
{
    /** @var array<string, mixed> */
    private array $originalServer = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalServer = service('superglobals')->getServerArray();
    }

    protected function tearDown(): void
    {
        service('superglobals')->setServerArray($this->originalServer);
        parent::tearDown();
    }

    public function testEdgeHeaderBeatsForwardedChain(): void
    {
        $request = $this->requestWith(['X-Forwarded-For' => '198.51.100.4'], '127.0.0.1');
        $request->setHeader('CF-Connecting-IP', '203.0.113.7');

        $this->assertSame('203.0.113.7', Request::resolveIp($request));
    }

    public function testForgedLeftmostForwardedEntryIsIgnoredFromUntrustedPeer(): void
    {
        $request = $this->requestWith(['X-Forwarded-For' => '8.8.8.8'], '203.0.113.9');

        $this->assertSame('203.0.113.9', Request::resolveIp($request));
    }

    public function testForwardedChainIsScannedFromTheRightBehindATrustedProxy(): void
    {
        $request = $this->requestWith(['X-Forwarded-For' => '203.0.113.9, 198.51.100.2'], '::1');

        $this->assertSame('198.51.100.2', Request::resolveIp($request));
    }

    /**
     * X-Real-IP is not something Cloudflare strips on the way through, so a
     * caller that reaches the origin directly could pick its own identity.
     */
    public function testRealIpHeaderAloneIsIgnored(): void
    {
        $request = $this->requestWith(['X-Real-IP' => '8.8.8.8'], '203.0.113.9');

        $this->assertSame('203.0.113.9', Request::resolveIp($request));
    }

    public function testUnparsableEverythingFallsBackToSafeDefault(): void
    {
        $request = $this->requestWith(['X-Forwarded-For' => 'not-an-ip'], '');

        $this->assertSame('0.0.0.0', Request::resolveIp($request));
    }

    public function testIdentityIsStableScopedAndOpaque(): void
    {
        $token = Request::identity('203.0.113.7');

        $this->assertSame($token, Request::identity('203.0.113.7'));
        $this->assertNotSame($token, Request::identity('203.0.113.8'));
        $this->assertSame(20, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{20}$/', $token);
    }

    /** @dataProvider cidrCases */
    public function testCidrMatching(bool $expected, string $ip, string $cidr): void
    {
        $this->assertSame($expected, Request::inCidr($ip, $cidr));
    }

    public static function cidrCases(): array
    {
        return [
            [true, '10.20.30.40', '10.0.0.0/8'],
            [false, '11.0.0.1', '10.0.0.0/8'],
            [true, '192.168.1.7', '192.168.1.0/24'],
            [true, '1.2.3.4', '0.0.0.0/0'],
            [false, 'not-an-ip', '10.0.0.0/8'],
            [false, '10.1.2.3', '10.0.0.0/33'],
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    private function requestWith(array $headers, string $remoteAddr): IncomingRequest
    {
        service('superglobals')->setServer('REMOTE_ADDR', $remoteAddr);
        $request = new IncomingRequest(config('App'), new URI('https://example.com/'), null, new UserAgent());

        foreach ($headers as $name => $value) {
            $request->setHeader($name, $value);
        }

        return $request;
    }
}
