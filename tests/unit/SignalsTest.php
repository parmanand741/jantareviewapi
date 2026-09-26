<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\Legacy\Request;
use App\Libraries\Legacy\Signals;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;

final class SignalsTest extends CIUnitTestCase
{
    // --- normalize / textHash ---------------------------------------------

    public function testTextHashIgnoresCasePunctuationAndSpacing(): void
    {
        $this->assertSame(
            Signals::textHash('Battery life is GREAT!!!'),
            Signals::textHash('   battery   life   is   great  ')
        );
    }

    public function testTextHashDistinguishesDifferentText(): void
    {
        $this->assertNotSame(Signals::textHash('terrible product'), Signals::textHash('great product'));
    }

    public function testTextHashIsBlankForPunctuationOnly(): void
    {
        $this->assertSame('', Signals::textHash('   !!!  '));
    }

    public function testTextHashHandlesDevanagari(): void
    {
        $this->assertSame(
            Signals::textHash('बैटरी लाइफ अच्छी है'),
            Signals::textHash('बैटरी   लाइफ   अच्छी   है')
        );
    }

    // --- flags ------------------------------------------------------------

    /** @dataProvider spamText */
    public function testFlagFires(string $expected, string $text, string $productName = ''): void
    {
        $this->assertContains($expected, Signals::flags($text, '', $productName));
    }

    public static function spamText(): array
    {
        return [
            ['short_link', 'check this https://bit.ly/3xYz'],
            ['body_link', 'visit https://mysite.example/shop now'],
            ['contact_details', 'call me on 9876543210 for offers'],
            ['contact_details', 'dm wa.me/919876543210'],
            ['repeated_phrase', 'best best best best best phone ever'],
            ['shouting', 'TOTALLY WORTH EVERY RUPEE BOUGHT IT TWICE'],
            ['run_on_string', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
            ['emoji_heavy', 'good 👍👍👍👍👍👍👍👍👍👍 product'],
            ['text_equals_product', 'boAt rockerz', 'Boat Rockerz'],
        ];
    }

    public function testTextEqualsUrlFlag(): void
    {
        $url = 'https://example.com/p/1';
        $this->assertContains('text_equals_url', Signals::flags($url, $url, ''));
    }

    /** @dataProvider honestReview */
    public function testHonestReviewsCarryNoFlags(string $text): void
    {
        $this->assertSame([], Signals::flags($text, 'https://www.flipkart.com/boat-rockerz/p/itm123', 'boAt Rockerz 450'));
    }

    public static function honestReview(): array
    {
        return [
            ['I have used this phone for six months. The battery easily lasts a full day and the camera is good in daylight.'],
            ['बैटरी लाइफ बहुत अच्छी है। एक दिन में एक बार चार्ज करना पड़ता है। कैमरा भी ठीक ठाक है।'],
            ['Delivery was delayed by a week, but the product itself works as described. Screen is bright, keys feel stiff.'],
            ['Excellent build quality! The 120Hz display is smooth and thermals stay cool while gaming. Worth the price.'],
        ];
    }

    // --- duplicateOf ------------------------------------------------------

    public function testDuplicateFoundAcrossDevicesButNotOwnDevice(): void
    {
        $hash = Signals::textHash('solid build and clear sound');
        $rows = [
            $this->row('REV-A', $hash, 'tok-other'),
            $this->row('REV-B', Signals::textHash('something else'), 'tok-other'),
        ];

        $this->assertSame(['id' => 'REV-A'], Signals::duplicateOf($rows, $hash, 'tok-new'));
        $this->assertSame([], Signals::duplicateOf($rows, $hash, 'tok-other'));
        $this->assertSame([], Signals::duplicateOf($rows, '', 'tok-new'));
    }

    public function testDuplicateIgnoresStaleRowsAndReplies(): void
    {
        $hash = Signals::textHash('solid build and clear sound');

        $this->assertSame([], Signals::duplicateOf([$this->row('REV-OLD', $hash, 'tok-other', '2020-01-01T00:00:00Z')], $hash, 'tok-new'));
        $this->assertSame([], Signals::duplicateOf([$this->row('RPL-C', $hash, 'tok-other', 'now', 'REV-A')], $hash, 'tok-new'));
    }

    // --- linkSiblings -----------------------------------------------------

    public function testLinkSiblingsCountsExactUrlOnly(): void
    {
        $rows = [$this->row('REV-1', 'h1', 't1'), $this->row('REV-2', 'h2', 't2')];

        $this->assertSame(2, Signals::linkSiblings($rows, 'https://x.test/p/1'));
        $this->assertSame(0, Signals::linkSiblings($rows, 'https://other.test/'));
        $this->assertSame(0, Signals::linkSiblings($rows, ''));
    }

    /**
     * A Reviews row laid out exactly as the sheet stores it (A..U).
     *
     * @return array<int, mixed>
     */
    private function row(string $id, string $textHash, string $token, string $when = 'now', string $parent = ''): array
    {
        return [
            $id, $when, 'https://x.test/p/1', '', 5, 'enc', 0, 0, 0, 'published', 0, 'prod',
            '', $parent, 'ch', $token, 'idem', 'plat', 0, $textHash, '',
        ];
    }
}
