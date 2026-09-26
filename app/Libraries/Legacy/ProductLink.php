<?php

declare(strict_types=1);

namespace App\Libraries\Legacy;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;

/**
 * Decides whether a submitted product URL really points at a product page.
 *
 * Three verdicts, and the middle one matters most:
 *   valid      — fetched and it looks like a live product page.
 *   unverified — we could not confirm it. Amazon and Flipkart block datacenter
 *                IPs, so a perfectly genuine link gets this verdict from
 *                Render. Submissions with it are still accepted.
 *   invalid    — provably unusable, never just unpopular with a shop: broken
 *                syntax, the store's homepage, a search/browse page, a domain
 *                that no longer exists, or an address on a private network.
 *
 * A link that already carries a store's own product-page shape is valid on its
 * own terms. The probe can only add a title and image to it, or prove the
 * address dead: Amazon serves a "continue shopping" interstitial to this
 * request from most server IPs, and reading that as a bad link told Indian
 * shoppers that their genuine product pages were not products.
 *
 * The probe talks to the resolved IP directly (CURLOPT_RESOLVE) and re-runs
 * every guard on each redirect hop, so this endpoint cannot be aimed at
 * internal addresses.
 */
final class ProductLink
{
    public const VALID      = 'valid';
    public const UNVERIFIED = 'unverified';
    public const INVALID    = 'invalid';

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    private const TIME_BUDGET     = 6.0;
    private const CONNECT_TIMEOUT = 2.5;
    private const READ_TIMEOUT    = 4.0;
    private const MAX_HOPS        = 2;
    private const MAX_BODY        = 196608;
    private const TOKEN_TTL       = 1800;

    /** A link whose entire path is one of these is a storefront, not a product. */
    private const LISTING_EXACT = [
        's', 'search', 'query', 'q', 'browse', 'b', 'shop', 'store', 'stores',
        'products', 'product', 'p', 'categories', 'category', 'collections',
        'collection', 'tags', 'tag', 'departments', 'deals', 'offers', 'sale',
        'new-arrivals', 'bestsellers', 'best-sellers', 'wishlist', 'hw', 'blog',
        'blogs', 'pages', 'sitemap', 'all', 'all-products', 'catalog', 'searchresults',
        'gp', 'ask', 'reviews', 'askallquestions', 'askallquestionsforaddress',
    ];

    /** These are listings even with one more segment (but never deeper). */
    private const LISTING_PREFIX = [
        's', 'search', 'query', 'b', 'gp', 'gp/browse', 'gp/bobr', 'categories',
        'category', 'collections', 'collection', 'tags', 'tag', 'blog', 'blogs',
        'departments', 'wishlist', 'hw',
    ];

    private const SEARCH_KEYS = [
        'q', 'query', 'search', 'searchtext', 'searchterm', 'searchquery',
        'keyword', 'keywords', 'keywordsall', 's', 'sk', 'k', 'str', 'text',
        'refinereq', 'showsearch',
    ];

    /** Matched against the path + query (never the host), always lowercased. */
    private const PRODUCT_PATTERNS = [
        'Amazon'     => ['~/(?:dp|gp/product|gp/aw/d|gp/aod|gp/offer-listing|product-reviews|gp/aw/customer-reviews)/[a-z0-9]{10}(?:/|$|\\?)~', '~[?&]asin=[a-z0-9]{10}~'],
        'Flipkart'   => ['~/p/itm[0-9a-f]+~', '~[?&]pid=[a-z0-9]+~', '~/product-reviews/~', '~/askadifference/~'],
        'Meesho'     => ['~/p/[a-z0-9]+~', '~/catalog/~'],
        'Ajio'       => ['~/p/[0-9]+~', '~/product/~'],
        'Myntra'     => ['~/product-page/~', '~/pd/~', '~/details~', '~[?&]mno=~'],
        'Snapdeal'   => ['~/p/[a-z0-9]+~', '~/product/~'],
        'Croma'      => ['~/p/[a-z0-9]+~', '~/product/~'],
        'Nykaa'      => ['~/products?/~', '~/p/[a-z0-9]+~'],
        'eBay'       => ['~/itm/~', '~/i/[0-9]+~'],
        'AliExpress' => ['~/item/[0-9]+~', '~/i/[0-9]+\.html~'],
        'Walmart'    => ['~/p/[^/]+/[0-9]+~'],
        'BestBuy'    => ['~/site/[^/]+/[0-9]+p~'],
        'Apple'      => ['~/shop/buy-~', '~/shop/product/~'],
        'Samsung'    => ['~/pd/ep/~', '~/p/[0-9]+~'],
        'TataCLiQ'   => ['~/p/[^/]+/[0-9]+~', '~/[a-z0-9%-]+\.html~'],
    ];

    /**
     * Registrable domains per vendor, matched as a whole label or a subdomain —
     * never as a substring. "amazon.deals-hub.test" contains "amazon" the way a
     * phishing page does, and a page like that must not earn our product-page
     * shape rules or an "Amazon" badge.
     */
    private const VENDOR_HOSTS = [
        'Amazon'     => ['amazon.in', 'amazon.com', 'amazon.co.uk', 'amazon.ca', 'amazon.de',
                         'amazon.fr', 'amazon.es', 'amazon.it', 'amazon.nl', 'amazon.sg',
                         'amazon.com.au', 'amazon.com.br', 'amazon.ae', 'amazon.co.jp', 'amzn.to'],
        'Flipkart'   => ['flipkart.com', 'flipkart.in'],
        'Meesho'     => ['meesho.com', 'meesho.in'],
        'Ajio'       => ['ajio.com', 'ajio.info'],
        'Myntra'     => ['myntra.com', 'myntraweb.com'],
        'Snapdeal'   => ['snapdeal.com'],
        'Croma'      => ['croma.com'],
        'Nykaa'      => ['nykaa.com', 'nykaaman.com'],
        'eBay'       => ['ebay.com', 'ebay.in', 'ebay.co.uk'],
        'AliExpress' => ['aliexpress.com', 'aliexpress.us'],
        'Walmart'    => ['walmart.com'],
        'BestBuy'    => ['bestbuy.com'],
        'Apple'      => ['apple.com'],
        'Samsung'    => ['samsung.com', 'samsung.in'],
        'TataCLiQ'   => ['tatacliq.com'],
    ];

    /** Store labels shown in the public feed, keyed the same way as above. */
    private const STORE_BADGES = [
        'amazon.in' => 'Amazon', 'amazon.com' => 'Amazon', 'amazon.co.uk' => 'Amazon',
        'amazon.ca' => 'Amazon', 'amazon.de' => 'Amazon', 'amazon.fr' => 'Amazon',
        'amazon.es' => 'Amazon', 'amazon.it' => 'Amazon', 'amazon.nl' => 'Amazon',
        'amazon.sg' => 'Amazon', 'amazon.com.au' => 'Amazon', 'amazon.com.br' => 'Amazon',
        'amazon.ae' => 'Amazon', 'amazon.co.jp' => 'Amazon', 'amzn.to' => 'Amazon',
        'flipkart.com' => 'Flipkart', 'flipkart.in' => 'Flipkart',
        'myntra.com' => 'Myntra', 'myntraweb.com' => 'Myntra',
        'ajio.com' => 'AJIO', 'ajio.info' => 'AJIO',
        'meesho.com' => 'Meesho', 'meesho.in' => 'Meesho',
        'snapdeal.com' => 'Snapdeal',
        'nykaa.com' => 'Nykaa', 'nykaaman.com' => 'Nykaa',
        'croma.com' => 'Croma',
        'reliancedigital.in' => 'Reliance Digital',
        'tatacliq.com' => 'Tata CLiQ',
        'jiomart.com' => 'JioMart',
        'paytmmall.com' => 'Paytm Mall',
        'shopclues.com' => 'ShopClues',
        'bigbasket.com' => 'BigBasket', 'blinkit.com' => 'Blinkit',
        'zeptonow.com' => 'Zepto', 'dmartready.com' => 'DMart',
        'lenskart.com' => 'Lenskart', 'pepperfry.com' => 'Pepperfry',
        'firstcry.com' => 'FirstCry',
        'ebay.com' => 'eBay', 'ebay.in' => 'eBay', 'ebay.co.uk' => 'eBay',
        'aliexpress.com' => 'AliExpress', 'aliexpress.us' => 'AliExpress',
        'walmart.com' => 'Walmart',
        'etsy.com' => 'Etsy',
        'bestbuy.com' => 'Best Buy',
        'apple.com' => 'Apple', 'samsung.com' => 'Samsung', 'samsung.in' => 'Samsung',
    ];

    /** Titles that mean the server answered 200 with an error page. */
    private const SOFT_404_TITLE = [
        '~^\s*(error|http error|not ?found|page not ?found|40[1-9]\b)~',
        '~\b40[1-9]\b.{0,24}(not ?found|error|page)~',
        '~(page|item|product|url|address|file)[a-z ]{0,14}\b(not found|was not found|no longer|can ?(t|not)\b|couldn)~',
        '~we (couldn|can)(‘|\')?t find~',
        '~(does ?n[o\']?t|do not) exist~',
        '~sorry,? (this|the) (page|product|item)~',
        '~this (page|product|item) (is no longer|cannot be)~',
    ];

    /** Signals that we were served an anti-bot wall instead of the product. */
    private const BOT_WALL = [
        '~\bcaptcha\b~',
        '~robot check|pardon (our interruption|us)\b|unusual traffic|automated access~',
        '~are you a (human|robot)|verify (that )?you are|confirm you are|prove (that )?you are~',
        '~access denied|access to this (page|site) (is|has been) denied|request (has been )?blocked~',
        '~enable (javascript|js) and cookies~',
        '~too many (failed )?requests~',
        '~type the characters you see~',
        '~attention required~',
    ];

    /**
     * Pages that are a shell rather than a product: an interstitial, a store
     * homepage, or a soft 404 wearing a 200. Never proof of a live product.
     */
    private const GENERIC_PAGE = [
        '~click the button below to continue shopping~',
        '~sorry!? we couldn(‘|\')?t find that page~',
        '~buy products online at best price~',
        '~sign ?in to continue~',
        '~no (products|items|results) found~',
        '~(access|page) temporary(?:ly)? (blocked|unavailable)~',
        '~^\s*(page|item|product) (could ?not|can ?not|wasn.t)\s+(be )?(found|loaded)~',
    ];

    /**
     * Structure first (free, and the only layer that judges the link itself),
     * then a live probe for everything the structure layer could not settle.
     * The probe may only reject what is provable about an address — that the
     * domain has gone, or that it points at a private network.
     */
    public static function inspect(string $url, bool $probe = true): array
    {
        $structure = self::structure($url);

        if ($structure['verdict'] === self::VALID) {
            if (!$probe) return $structure;

            $probed = self::probe($url);
            // Only what is provable about the address itself can overrule a real
            // product shape: a domain that has gone, or one that dials a private
            // network. Both come back from probe() as INVALID.
            if ($probed['verdict'] === self::INVALID) return $probed;
            // Reaching the actual page is strictly better — it carries the title
            // and image the reviewer sees in the preview.
            if ($probed['verdict'] === self::VALID) return $probed;

            // A bot wall, an interstitial or a timeout is a verdict about our
            // server, not about the link, so keep the structural answer and
            // record that nothing was confirmed.
            return array_merge($structure, ['code' => 'structure_only', 'probeCode' => $probed['code']]);
        }

        if (!$probe || $structure['verdict'] === self::INVALID) {
            return $structure;
        }

        $probed = self::probe($url);

        if ($probed['verdict'] !== self::UNVERIFIED) {
            return $probed;
        }
        // Keep whatever the network said, but carry the structural read-forward.
        $probed['vendor'] = $probed['vendor'] !== '' ? $probed['vendor'] : $structure['vendor'];
        $probed['product'] = $probed['product'] || $structure['product'];
        return $probed;
    }

    /**
     * Syntax and page-shape rules only — no DNS, no sockets. Deliberately
     * permissive: unknown sites pass and let the probe decide.
     */
    public static function structure(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return self::result(self::INVALID, 'bad_url', 'That is not a complete web address. Paste the full link from your browser’s address bar.');
        }
        if (strlen((string) ($parts['path'] ?? '')) > 2000) {
            return self::result(self::INVALID, 'bad_url', 'That link is too long to be a product address. Use the site’s own “share” link instead.');
        }

        $host = strtolower((string) $parts['host']);
        $path = '/' . trim((string) ($parts['path'] ?? ''), '/');
        $target = strtolower($path . (isset($parts['query']) ? '?' . $parts['query'] : ''));
        $segments = $path === '/' ? [] : explode('/', trim($path, '/'));
        $first = strtolower($segments[0] ?? '');

        $vendor = self::vendorFor($host);
        $isProduct = false;
        foreach (self::PRODUCT_PATTERNS[$vendor] ?? [] as $pattern) {
            if (preg_match($pattern, $target) === 1) {
                $isProduct = true;
                break;
            }
        }

        if (!$isProduct) {
            if ($segments === []) {
                $search = array_intersect_key(
                    array_fill_keys(self::SEARCH_KEYS, true),
                    self::queryKeys((string) ($parts['query'] ?? ''))
                );
                if ($search !== []) {
                    return self::result(self::INVALID, 'search_page', 'That is a search-results page. Open the product itself and copy its address.');
                }
                return self::result(self::INVALID, 'homepage_only', 'That is the ‘' . $host . '’ homepage. Please link the product page, not the store.');
            }
            $listing = count($segments) === 1
                ? in_array($first, self::LISTING_EXACT, true)
                : (count($segments) === 2 && in_array($first, self::LISTING_PREFIX, true));
            if ($listing) {
                return self::result(self::INVALID, 'not_product_page', 'That is a browse or listing page, not one product. Open the product and copy its address.');
            }
        }

        return $isProduct
            ? self::result(self::VALID, 'structure_ok', 'Looks like a product page on ' . $vendor . '.', ['vendor' => $vendor, 'product' => true])
            : self::result(self::UNVERIFIED, 'unknown_site', 'We will load that link to confirm it is a real product page.', ['vendor' => $vendor]);
    }

    /**
     * Resolve, pin, fetch, read. What a server answers is never proof of a fake
     * link — only its addresses are: a domain that has gone, or one that points
     * somewhere private.
     */
    public static function probe(string $url, ?float $started = null): array
    {
        $started ??= microtime(true);
        $current = $url;

        for ($hop = 0; ; $hop++) {
            $parts = parse_url($current);
            // parse_url keeps the brackets on IPv6 literals.
            $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
            $port = (int) ($parts['port'] ?? 443);
            if ($host === '' || ($parts['scheme'] ?? 'https') !== 'https') {
                return self::result(self::UNVERIFIED, 'bad_redirect', 'That link ends somewhere we cannot check over https.');
            }
            if ($port !== 443) {
                return self::result(self::UNVERIFIED, 'unusual_port', 'That link uses a non-standard port, so we could not confirm it.');
            }

            $resolved = self::publicAddresses($host);
            if ($resolved['error'] === 'dead_domain') {
                return self::result(self::INVALID, 'dead_domain', 'That web address no longer exists (‘' . $host . '’ cannot be found). Paste the link to a live product page.');
            }
            if ($resolved['error'] === 'blocked_host') {
                return self::result(self::INVALID, 'blocked_host', 'That link points at a private network address, which cannot be a public product page.');
            }
            if ($resolved['error'] !== '') {
                return self::result(self::UNVERIFIED, 'lookup_failed', 'We could not look up that address from here, so we could not confirm it.');
            }

            $remaining = self::TIME_BUDGET - (microtime(true) - $started);
            if ($remaining <= 0.3) {
                return self::result(self::UNVERIFIED, 'timeout', 'That link took too long to respond, so we could not confirm it.');
            }

            try {
                $response = self::client($host, $port, $resolved['ips'], $remaining)
                    ->request('GET', $current, ['stream' => true]);
                $status = $response->getStatusCode();
                $body = self::drain($response->getBody());
                $finalUrl = $current;
            } catch (TransferException $e) {
                log_message('info', 'Product link probe failed: {message}', ['message' => $e->getMessage()]);
                return self::result(self::UNVERIFIED, 'unreachable', 'The product site would not answer us from here, so this link is unconfirmed.');
            } catch (\Throwable $e) {
                log_message('error', 'Product link probe error: {message}', ['message' => $e->getMessage()]);
                return self::result(self::UNVERIFIED, 'unreachable', 'The product site would not answer us from here, so this link is unconfirmed.');
            }

            if ($status < 300 || $status >= 400) {
                return self::classify($status, $body, $finalUrl);
            }

            $next = self::absolute((string) $response->getHeaderLine('Location'), $current);
            if ($next === '' || $hop >= self::MAX_HOPS) {
                return self::result(self::UNVERIFIED, 'redirect', 'That link keeps moving, so we could not follow it to a product page.');
            }
            $moved = self::structure($next);
            if ($moved['verdict'] === self::INVALID) {
                // The site chose that hop, and shops redirect automated checks to
                // their homepage or a login wall more often than to the product.
                return self::result(self::UNVERIFIED, 'redirected_to_listing', 'That link moves to a page we could not confirm as a product.', ['vendor' => $moved['vendor']] + ['finalUrl' => $next]);
            }
            if (strtolower((string) (parse_url($next, PHP_URL_HOST) ?? '')) !== $host) {
                return self::probe($next, $started);
            }
            $current = $next;
        }
    }

    private static function classify(int $status, string $body, string $finalUrl): array
    {
        $meta = self::metadata($body);
        $extra = $meta + ['finalUrl' => $finalUrl];

        // A shop's own "not found" is not evidence — Myntra serves an identical
        // 404 page to a live product and to a made-up one. Showing its title as a
        // preview would read like confirmation the product is gone, so drop it.
        if ($status === 404 || $status === 410) {
            return self::result(self::UNVERIFIED, 'http_404', 'The site answered “page not found”, although shops sometimes answer that to an automated check.', ['finalUrl' => $finalUrl]);
        }
        if ($status >= 500) {
            return self::result(self::UNVERIFIED, 'server_error', 'The product site is having trouble right now, so we could not confirm it.', $extra);
        }
        if ($status === 403 || $status === 429) {
            return self::result(self::UNVERIFIED, 'bot_blocked', 'The product site blocks automated checks, so we could not confirm it from our server.', $extra);
        }
        if ($status >= 400) {
            return self::result(self::UNVERIFIED, 'rejected', 'The product site did not answer that link normally, so we could not confirm it.', $extra);
        }
        if ($status < 200) {
            return self::result(self::UNVERIFIED, 'unreachable', 'We could not read that page from here, so this link is unconfirmed.', $extra);
        }

        // Block walls put their message at the very top; scanning the whole page
        // would trip on ordinary product copy that mentions captcha or robots.
        // Every pattern below is written lowercase and matched case-insensitively.
        $head = strtolower(self::visibleHead($body));
        $titleLc = strtolower($meta['title']);
        $haystack = $titleLc . ' | ' . $head;
        foreach (self::BOT_WALL as $pattern) {
            if (preg_match($pattern, $haystack) === 1) {
                return self::result(self::UNVERIFIED, 'bot_blocked', 'The product site stopped our check with a security page, so we could not confirm it.', $extra);
            }
        }
        foreach (self::SOFT_404_TITLE as $pattern) {
            if (preg_match($pattern, $titleLc) === 1) {
                return self::result(self::UNVERIFIED, 'declared_missing', 'That page announces itself as missing, but shops do say that to automated checks.', ['finalUrl' => $finalUrl]);
            }
        }
        $bare = strtolower(preg_replace('~^www\.~', '', (string) (parse_url($finalUrl, PHP_URL_HOST) ?? '')));
        if (self::genericPage($meta['title'], $haystack, $bare)) {
            return self::result(self::UNVERIFIED, 'generic_page', 'The site answered with its front page rather than the product, so we could not confirm it.', $extra);
        }
        if (mb_strlen($meta['title']) < 3 && strlen($body) < 400) {
            return self::result(self::UNVERIFIED, 'empty_page', 'That page came back with nothing readable on it, so we could not confirm it.', $extra);
        }

        $probe = self::structure($finalUrl);
        if ($probe['verdict'] === self::VALID) {
            return self::result(self::VALID, 'product_confirmed', $probe['message'], $extra + ['vendor' => $probe['vendor'], 'product' => true]);
        }
        return self::result(self::UNVERIFIED, 'page_loaded', 'The page loads, but we cannot tell for certain that it is a product page.', $extra + ['vendor' => $probe['vendor']]);
    }

    private static function visibleHead(string $body): string
    {
        // strip_tags() keeps <script> contents, which would fill the head with JS.
        $text = preg_replace('~<(script|style|noscript)\b.*?</\1>~is', ' ', $body) ?? $body;
        $text = preg_replace('~\s+~', ' ', strip_tags($text)) ?? '';
        return substr(trim($text), 0, 1500);
    }

    private static function genericPage(string $title, string $haystack, string $host): bool
    {
        $t = trim($title);
        if ($t !== '') {
            if ($host !== '' && mb_strtolower($t) === $host) {
                return true;
            }
            if (preg_match('~^(shop|home|homepage|welcome)\.?$~i', $t) === 1) {
                return true;
            }
        }
        foreach (self::GENERIC_PAGE as $pattern) {
            if (preg_match($pattern, $haystack) === 1) {
                return true;
            }
        }
        return false;
    }

    private static function client(string $host, int $port, array $ips, float $remaining): Client
    {
        return new Client([
            // WAMP PHP ships without curl.cainfo; null on Render means the system store.
            'verify' => Sheets::findCaBundle() ?? true,
            'http_errors' => false,
            'allow_redirects' => false,
            'protocol_version' => 1.1,
            'headers' => [
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language' => 'en-IN,en;q=0.9',
                'Cache-Control' => 'no-cache',
            ],
            'curl' => [
                CURLOPT_RESOLVE => array_map(
                    static fn(string $ip): string => $host . ':' . $port . ':' . $ip,
                    $ips
                ),
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_CONNECTTIMEOUT => (int) ceil(min(self::CONNECT_TIMEOUT, max(1.0, $remaining))),
                CURLOPT_TIMEOUT => (int) ceil(min(self::READ_TIMEOUT, max(1.0, $remaining))),
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_ENCODING => '',
            ],
        ]);
    }

    /**
     * Resolve a hostname down to addresses we are willing to dial. Anything
     * loopback, private, link-local, shared or multicast fails closed.
     */
    private static function publicAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return self::isPublicIp((string) $host)
                ? ['ips' => [$host], 'error' => '']
                : ['ips' => [], 'error' => 'blocked_host'];
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isPublicIpv6((string) $host)
                ? ['ips' => [], 'error' => 'ipv6_only']
                : ['ips' => [], 'error' => 'blocked_host'];
        }

        $records = @dns_get_record($host, DNS_A);
        if ($records === false) {
            return ['ips' => [], 'error' => self::resolverHealthy() ? 'unresolved' : 'resolver_error'];
        }
        $ips = array_values(array_unique(array_column($records, 'ip')));
        if ($ips === []) {
            if (@checkdnsrr($host, 'AAAA')) {
                return ['ips' => [], 'error' => 'ipv6_only'];
            }
            // Blame the domain only when our own resolver is demonstrably working,
            // so a flaky DNS box can never reject every submission on the site.
            return self::resolverHealthy()
                ? ['ips' => [], 'error' => 'dead_domain']
                : ['ips' => [], 'error' => 'resolver_error'];
        }
        $public = array_values(array_filter($ips, static fn(string $ip): bool => self::isPublicIp($ip)));
        if ($public !== []) {
            return ['ips' => $public, 'error' => ''];
        }
        return ['ips' => [], 'error' => 'blocked_host'];
    }

    private static function resolverHealthy(): bool
    {
        foreach (['google.com', 'cloudflare.com', 'example.com'] as $canary) {
            if (@checkdnsrr($canary, 'A') === true) {
                return true;
            }
        }
        return false;
    }

    private static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        // RFC 6598 shared address space is outside both of the flags above.
        return !preg_match('~^100\.(6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\.~', $ip);
    }

    private static function isPublicIpv6(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private static function queryKeys(string $query): array
    {
        $keys = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') continue;
            $keys[strtolower(explode('=', $pair, 2)[0])] = true;
        }
        return $keys;
    }

    private static function absolute(string $target, string $base): string
    {
        $target = trim(str_replace(["\r", "\n", "\t"], '', $target));
        if ($target === '') {
            return '';
        }
        if (preg_match('~^https?://~i', $target) === 1) {
            return str_starts_with(strtolower($target), 'https://') ? $target : '';
        }
        $parts = parse_url($base);
        $origin = 'https://' . (string) ($parts['host'] ?? '');
        if (str_starts_with($target, '//')) {
            return $origin . substr($target, 1);
        }
        if (str_starts_with($target, '/')) {
            return $origin . $target;
        }
        $dir = rtrim(str_replace('\\', '/', dirname((string) ($parts['path'] ?? '/'))), '/');
        return $origin . $dir . '/' . $target;
    }

    private static function drain($stream): string
    {
        $body = '';
        try {
            while (!$stream->eof() && strlen($body) < self::MAX_BODY) {
                $chunk = $stream->read(16384);
                if ($chunk === '') break;
                $body .= $chunk;
            }
        } catch (\Throwable $e) {
            // A truncated body still gives us the title, which is all we need.
        }
        return $body;
    }

    /** @return array{title:string,image:string,siteName:string} */
    private static function metadata(string $body): array
    {
        $title = self::firstMatch($body, '~<meta[^>]+property=["\']og:title["\'][^>]*content=["\'](.*?)["\']~i')
            ?? self::firstMatch($body, '~<meta[^>]+content=["\'](.*?)["\'][^>]*property=["\']og:title["\']~i')
            ?? self::firstMatch($body, '~<meta[^>]+name=["\']twitter:title["\'][^>]*content=["\'](.*?)["\']~i')
            ?? self::firstMatch($body, '~<title[^>]*>(.*?)</title>~is')
            ?? '';

        $image = self::firstMatch($body, '~<meta[^>]+property=["\']og:image["\'][^>]*content=["\'](.*?)["\']~i')
            ?? self::firstMatch($body, '~<meta[^>]+content=["\'](.*?)["\'][^>]*property=["\']og:image["\']~i')
            ?? self::firstMatch($body, '~<meta[^>]+name=["\']twitter:image["\'][^>]*content=["\'](.*?)["\']~i')
            ?? '';

        $site = self::firstMatch($body, '~<meta[^>]+property=["\']og:site_name["\'][^>]*content=["\'](.*?)["\']~i') ?? '';

        return [
            'title' => self::clean((string) $title, 180),
            'image' => self::cleanImage((string) $image),
            'siteName' => self::clean((string) $site, 60),
        ];
    }

    private static function firstMatch(string $subject, string $pattern): ?string
    {
        return preg_match($pattern, $subject, $m) === 1 && $m[1] !== '' ? $m[1] : null;
    }

    /**
     * A page's own title is attacker text, and it arrives entity-encoded, so
     * decoding has to come first: "&lt;img onerror=…&gt;" survives strip_tags()
     * untouched and only becomes markup on the way out.
     */
    private static function clean(string $raw, int $max): string
    {
        $s = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $s = trim(strip_tags($s));
        $s = str_replace(['<', '>', '"', "'"], ' ', $s);
        $s = preg_replace('~[\x{0000}-\x{001F}\x{007F}-\x{009F}]~u', ' ', $s) ?? $s;
        $s = trim(preg_replace('~\s+~u', ' ', $s) ?? $s);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//TRANSLIT//IGNORE', $s);
            if (is_string($converted) && $converted !== '') $s = $converted;
        }
        return mb_substr($s, 0, $max);
    }

    private static function cleanImage(string $raw): string
    {
        return (string) Validator::url(html_entity_decode(trim($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** True when $host is $domain itself or a subdomain of it. */
    private static function isOnDomain(string $host, string $domain): bool
    {
        return $host === $domain || str_ends_with($host, '.' . $domain);
    }

    /**
     * The label shown on a review card. Display only — nothing about a link's
     * validity is derived from it, and an unrecognised domain earns no badge.
     */
    public static function storeFor(string $host): ?string
    {
        $host = strtolower(rtrim(trim($host), '.'));
        if ($host === '') return null;

        foreach (self::STORE_BADGES as $domain => $badge) {
            if (self::isOnDomain($host, $domain)) return $badge;
        }
        return null;
    }

    private static function vendorFor(string $host): string
    {
        $host = strtolower(rtrim($host, '.'));
        foreach (self::VENDOR_HOSTS as $vendor => $domains) {
            foreach ($domains as $domain) {
                if (self::isOnDomain($host, $domain)) return $vendor;
            }
        }
        return '';
    }

    private static function result(string $verdict, string $code, string $message, array $extra = []): array
    {
        return array_merge([
            'verdict' => $verdict,
            'code' => $code,
            'message' => $message,
            'title' => '',
            'image' => '',
            'siteName' => '',
            'finalUrl' => '',
            'vendor' => '',
            'product' => false,
        ], $extra);
    }

    /** Short-lived proof that this exact URL was checked by us. */
    public static function issue(string $normalizedUrl, string $verdict, string $code): string
    {
        $payload = (time() + self::TOKEN_TTL) . '|' . $verdict . '|' . $code;
        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=') . '.' . self::mac($normalizedUrl, $payload);
    }

    /** @return array{verdict:string,code:string}|null */
    public static function consume(string $normalizedUrl, string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > 256 || substr_count($token, '.') !== 1) {
            return null;
        }
        [$body, $mac] = explode('.', $token, 2);
        $decoded = base64_decode(strtr($body, '-_', '+/'), true);
        if (!is_string($decoded) || substr_count($decoded, '|') !== 2) {
            return null;
        }
        [$expires, $verdict, $code] = explode('|', $decoded, 3);
        if ((int) $expires < time() || !in_array($verdict, [self::VALID, self::UNVERIFIED], true)) {
            return null;
        }
        if (!hash_equals(self::mac($normalizedUrl, $decoded), $mac)) {
            return null;
        }
        return ['verdict' => $verdict, 'code' => $code];
    }

    private static function mac(string $normalizedUrl, string $payload): string
    {
        return hash_hmac('sha256', 'link|' . $normalizedUrl . '|' . $payload, (string) Config::get('encryption_key'));
    }
}
