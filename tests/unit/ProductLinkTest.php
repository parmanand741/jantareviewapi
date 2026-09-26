<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\Legacy\ProductLink;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Structure rules only — no sockets. The probe is deliberately never called
 * here: a test suite that reached Amazon would be flaky by design.
 */
final class ProductLinkTest extends CIUnitTestCase
{
    /** @dataProvider productShapes */
    public function testStoreProductShapesAreValid(string $url): void
    {
        $result = ProductLink::structure($url);
        $this->assertSame(ProductLink::VALID, $result['verdict'], $url . ' => ' . $result['code']);
        $this->assertSame('structure_ok', $result['code'], $url);
        $this->assertTrue($result['product'], $url);
    }

    public static function productShapes(): array
    {
        return [
            'amazon titled url'      => ['https://www.amazon.in/Professional-Cordless-Capacity-Included-Warranty/dp/B07WLVV8Y3/'],
            'amazon bare'            => ['https://www.amazon.in/dp/B07WLVV8Y3'],
            'amazon with variants'   => ['https://www.amazon.in/dp/B07WLVV8Y3?th=1&psc=1'],
            'amazon search result'   => ['https://www.amazon.in/Bosch-GSO-8-Professional/dp/B08KQWWS3L/ref=sr_1_3?keywords=bosch'],
            'amazon legacy gp'       => ['https://www.amazon.in/gp/product/B07WLVV8Y3/'],
            'amazon mobile'          => ['https://www.amazon.in/gp/aw/d/B07WLVV8Y3'],
            'amazon reviews tab'     => ['https://www.amazon.in/product-reviews/B07WLVV8Y3/'],
            'flipkart item'          => ['https://www.flipkart.com/bosch-gso-8-professional/p/itma1b2c3d4e5f6g'],
            'myntra details'         => ['https://www.myntra.com/shirt/nike/roadster/12345678/details'],
        ];
    }

    /** @dataProvider nonProductShapes */
    public function testLinksThatAreNotOneProductPage(string $url, string $verdict, string $code): void
    {
        $result = ProductLink::structure($url);
        $this->assertSame($verdict, $result['verdict'], $url . ' => ' . $result['code']);
        $this->assertSame($code, $result['code'], $url);
    }

    public static function nonProductShapes(): array
    {
        return [
            'homepage'            => ['https://www.amazon.in/', 'invalid', 'homepage_only'],
            'keyword search'      => ['https://www.amazon.in/s?k=bosch+drill', 'invalid', 'not_product_page'],
            'root search'         => ['https://www.amazon.in/?k=bosch+drill', 'invalid', 'search_page'],
            'browse node'         => ['https://www.amazon.in/b?node=1374546031', 'invalid', 'not_product_page'],
            'dp without an asin'  => ['https://www.amazon.in/dp/NOTANASIN', 'unverified', 'unknown_site'],
            'dp with a long asin' => ['https://www.amazon.in/dp/B07WLVV8Y3XYZ/', 'unverified', 'unknown_site'],
            'empty dp'            => ['https://www.amazon.in/dp/', 'unverified', 'unknown_site'],
            'unknown shop'        => ['https://example.com/bosch-drill', 'unverified', 'unknown_site'],
        ];
    }

    /** The path segment after /dp/ is an ASIN: exactly ten alphanumeric characters. */
    public function testAsinSegmentMustBeExactlyTenCharacters(): void
    {
        $this->assertSame(ProductLink::VALID, ProductLink::structure('https://www.amazon.in/dp/abcdefghij')['verdict']);
        $this->assertSame(ProductLink::UNVERIFIED, ProductLink::structure('https://www.amazon.in/dp/abcdefghi')['verdict']);
    }

    /**
     * A link the structure layer is certain about stays certain whatever a
     * blocked probe answers — that was the reported bug on genuine Amazon links.
     */
    public function testStructurallyValidLinkNeedsNoProbeToBeAccepted(): void
    {
        $url = 'https://www.amazon.in/Professional-Cordless-Capacity-Included-Warranty/dp/B07WLVV8Y3/';
        $result = ProductLink::inspect($url, false);
        $this->assertSame(ProductLink::VALID, $result['verdict']);
        $this->assertSame('Amazon', $result['vendor']);
    }

    public function testLinkTokenSurvivesTheStructureOnlyVerdict(): void
    {
        $url = 'https://www.amazon.in/dp/B07WLVV8Y3';
        $token = ProductLink::issue($url, ProductLink::VALID, 'structure_only');
        $trusted = ProductLink::consume($url, $token);

        $this->assertNotNull($trusted, 'a valid-but-unconfirmed link must still be usable');
        $this->assertSame(ProductLink::VALID, $trusted['verdict']);
        $this->assertSame('structure_only', $trusted['code']);
        $this->assertNull(ProductLink::consume('https://www.amazon.in/dp/B000000000', $token));
    }

    /**
     * A product-page shape only means something on the shop that invented it.
     * "amazon.deals-hub.test/dp/…" is a page an attacker wrote, and the old
     * substring match handed it an Amazon verdict and an Amazon badge.
     *
     * @dataProvider lookalikeHosts
     */
    public function testLookalikeHostsEarnNoStoreVerdict(string $url): void
    {
        $result = ProductLink::structure($url);
        $this->assertNotSame(ProductLink::VALID, $result['verdict'], $url);
        $this->assertSame('', $result['vendor'], $url);
    }

    public static function lookalikeHosts(): array
    {
        return [
            'amazon as a prefix label' => ['https://amazon.deals-hub.test/dp/B07WLVV8Y3'],
            'amazon inside the name'   => ['https://notamazon.com/dp/B07WLVV8Y3'],
            'amazon as a subdomain'    => ['https://amazon.evil.example/dp/B07WLVV8Y3'],
            'flipkart as a suffix'     => ['https://notflipkart.com/bosch/p/itm1234'],
            'myntra before the tld'    => ['https://myntra.attacker.io/1234/details'],
            'apple inside the host'    => ['https://myapple.com.phones.example/shop/buy-iphone'],
        ];
    }

    /** @dataProvider lookalikeHosts */
    public function testLookalikeHostsEarnNoStoreBadge(string $url): void
    {
        $this->assertNull(ProductLink::storeFor((string) parse_url($url, PHP_URL_HOST)), $url);
    }

    public function testStoreBadgesNeedARealDomainAndMayUseSubdomains(): void
    {
        $this->assertSame('Amazon', ProductLink::storeFor('www.amazon.in'));
        $this->assertSame('Amazon', ProductLink::storeFor('smile.amazon.co.uk'));
        $this->assertSame('Amazon', ProductLink::storeFor('amzn.to'));
        $this->assertSame('Flipkart', ProductLink::storeFor('m.flipkart.com'));
        $this->assertSame('AJIO', ProductLink::storeFor('www.ajio.com'));
        $this->assertNull(ProductLink::storeFor('amazon'));
        $this->assertNull(ProductLink::storeFor(''));
    }

    public function testASINShapesOnRealStoreDomainsStayValid(): void
    {
        $this->assertSame(ProductLink::VALID, ProductLink::structure('https://smile.amazon.in/dp/B07WLVV8Y3')['verdict']);
        $this->assertSame(ProductLink::VALID, ProductLink::structure('https://www.amazon.com/dp/B07WLVV8Y3')['verdict']);
        $this->assertSame('Amazon', ProductLink::structure('https://www.amazon.com/dp/B07WLVV8Y3')['vendor']);
    }

    /**
     * A page's own title is attacker text. Decoding after stripping let an
     * entity-encoded tag leave clean() as live markup.
     */
    public function testCleanedMetadataCarriesNoMarkup(): void
    {
        $clean = new \ReflectionMethod(ProductLink::class, 'clean');
        $clean->setAccessible(true);
        $out = $clean->invokeArgs(null, ['&lt;img src=x onerror=alert(1)&gt; Battery &quot;great&quot;', 180]);

        $this->assertStringNotContainsString('<', $out);
        $this->assertStringNotContainsString('>', $out);
        $this->assertStringNotContainsString('"', $out);
        $this->assertStringContainsString('Battery', $out);
    }

    public function testCleanedMetadataKeepsOrdinaryTitles(): void
    {
        $clean = new \ReflectionMethod(ProductLink::class, 'clean');
        $clean->setAccessible(true);
        $out = $clean->invokeArgs(null, ['Bosch GSO 8 Professional &amp; 900W | Amazon.in', 180]);

        $this->assertSame('Bosch GSO 8 Professional & 900W | Amazon.in', $out);
    }
}
