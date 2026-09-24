<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\Legacy\Validator;
use CodeIgniter\Test\CIUnitTestCase;

final class ReviewValidationTest extends CIUnitTestCase
{
    public function testSanitizeRemovesMarkupAndControls(): void
    {
        $this->assertSame('hello world', Validator::sanitize(" <b>hello</b>\u{200B} world "));
    }

    public function testUrlOnlyAcceptsPublicHttpsAndRemovesTracking(): void
    {
        $this->assertSame(
            'https://example.com/item?id=42',
            Validator::url('https://example.com/item?id=42&utm_source=test')
        );
        $this->assertSame('', Validator::url('http://localhost/item'));
        $this->assertSame('', Validator::url('https://127.0.0.1/item'));
    }

    public function testStarsAndStatusAreNormalized(): void
    {
        $this->assertSame(5, Validator::stars('5'));
        $this->assertSame(0, Validator::stars(6));
        $this->assertSame('archived', Validator::status('quarantined'));
        $this->assertSame('published', Validator::status('unknown'));
    }
}
