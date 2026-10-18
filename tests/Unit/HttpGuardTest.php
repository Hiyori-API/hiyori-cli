<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/public/http-guard.php';

/**
 * The same-origin check is the only thing between any page the operator visits
 * and the state-changing routes on this dashboard. It used to live inside
 * public/index.php, where no test could reach it; it is a pure function over
 * $_SERVER, so it can be exercised directly here.
 */
final class HttpGuardTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server = [];

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        unset($_SERVER['HTTP_SEC_FETCH_SITE'], $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_HOST']);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    /** @param array<string, string> $headers */
    private function withHeaders(array $headers): bool
    {
        $_SERVER = array_merge($_SERVER, $headers);

        return \sameOriginRequest();
    }

    public function testNonBrowserClientWithoutOriginIsAllowed(): void
    {
        // curl and the CLI send neither header; refusing them would break the
        // local tool without protecting anything.
        $this->assertTrue($this->withHeaders([]));
    }

    public function testCrossSiteFetchMetadataIsRejected(): void
    {
        $this->assertFalse($this->withHeaders([
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
            'HTTP_ORIGIN' => 'http://localhost:8080',
            'HTTP_HOST' => 'localhost:8080',
        ]));
    }

    public function testLoopbackByHostnameIsAllowed(): void
    {
        $this->assertTrue($this->withHeaders([
            'HTTP_ORIGIN' => 'http://localhost:8080',
            'HTTP_HOST' => 'localhost:8080',
        ]));
    }

    public function testLoopbackByAddressIsAllowed(): void
    {
        $this->assertTrue($this->withHeaders([
            'HTTP_ORIGIN' => 'http://127.0.0.1:8080',
            'HTTP_HOST' => '127.0.0.1:8080',
        ]));
    }

    public function testIpv6LiteralIsAllowed(): void
    {
        $this->assertTrue($this->withHeaders([
            'HTTP_ORIGIN' => 'http://[::1]:8080',
            'HTTP_HOST' => '[::1]:8080',
        ]));
    }

    public function testPrivateNetworkAddressIsAllowed(): void
    {
        // Binding 0.0.0.0 and reaching the dashboard by LAN address is a
        // supported arrangement.
        $this->assertTrue($this->withHeaders([
            'HTTP_ORIGIN' => 'http://192.168.1.5:8080',
            'HTTP_HOST' => '192.168.1.5:8080',
        ]));
    }

    public function testDnsNameIsRejectedEvenWhenOriginAndHostAgree(): void
    {
        // DNS rebinding: evil.com is re-pointed at 127.0.0.1, so the browser
        // sends Origin: http://evil.com and Host: evil.com and treats the
        // request as same-origin. Matching the two proves nothing.
        $this->assertFalse($this->withHeaders([
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
            'HTTP_ORIGIN' => 'http://evil.com',
            'HTTP_HOST' => 'evil.com:8080',
        ]));
    }

    public function testOriginMustMatchTheHostHeader(): void
    {
        $this->assertFalse($this->withHeaders([
            'HTTP_ORIGIN' => 'http://localhost:8080',
            'HTTP_HOST' => '127.0.0.1:8080',
        ]));
    }

    public function testUnparseableOriginIsRejected(): void
    {
        $this->assertFalse($this->withHeaders([
            'HTTP_ORIGIN' => 'http://',
            'HTTP_HOST' => 'localhost:8080',
        ]));
    }
}
