<?php

declare(strict_types=1);

namespace Hanane\OutboundGuardBundle\Tests\Unit\Metadata;

use Hanane\OutboundGuardBundle\Metadata\RequestTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestTargetTest extends TestCase
{
    public function testDefaultMetadataHidesCredentialsQueryFragmentAndPath(): void
    {
        $target = RequestTarget::fromUrl('GET', 'https://user:password@API.example.com:443/reset/path-secret?api_key=query-secret#fragment-secret', 'key');

        self::assertSame('api.example.com', $target->host);
        self::assertNull($target->path);
        self::assertSame('https://api.example.com/[path hidden]', $target->displayUrl);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $target->fingerprint);

        foreach (['user', 'password', 'path-secret', 'query-secret', 'fragment-secret'] as $secret) {
            self::assertStringNotContainsString($secret, serialize($target));
        }
    }

    public function testPathsRequireAnExplicitOptIn(): void
    {
        $target = RequestTarget::fromUrl('GET', 'https://api.example.com/products/42?token=secret', 'key', true);

        self::assertSame('/products/42', $target->path);
        self::assertSame('https://api.example.com/products/42', $target->displayUrl);
    }

    #[DataProvider('equivalentTargets')]
    public function testEquivalentEndpointsHaveTheSameFingerprint(string $first, string $second): void
    {
        self::assertSame(
            RequestTarget::fromUrl('GET', $first, 'key')->fingerprint,
            RequestTarget::fromUrl('GET', $second, 'key')->fingerprint,
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function equivalentTargets(): iterable
    {
        yield 'HTTPS default port and host case' => ['https://API.example.com:443/products', 'https://api.example.com/products'];
        yield 'HTTP default port' => ['http://api.example.com:80/', 'http://api.example.com/'];
        yield 'empty path' => ['https://api.example.com', 'https://api.example.com/'];
        yield 'all query values ignored' => ['https://api.example.com/items?page=1&token=secret', 'https://api.example.com/items?page=2'];
        yield 'credentials and fragments ignored' => ['https://user:password@api.example.com/items#secret', 'https://api.example.com/items'];
    }

    #[DataProvider('distinctTargets')]
    public function testSignificantUrlDifferencesArePreserved(string $first, string $second): void
    {
        self::assertNotSame(
            RequestTarget::fromUrl('GET', $first, 'key')->fingerprint,
            RequestTarget::fromUrl('GET', $second, 'key')->fingerprint,
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function distinctTargets(): iterable
    {
        yield 'path case' => ['https://api.example.com/Items', 'https://api.example.com/items'];
        yield 'trailing slash' => ['https://api.example.com/items', 'https://api.example.com/items/'];
        yield 'encoded separator' => ['https://api.example.com/items%2F42', 'https://api.example.com/items/42'];
        yield 'non-default port' => ['https://api.example.com:8443/items', 'https://api.example.com/items'];
        yield 'scheme' => ['https://api.example.com/items', 'http://api.example.com/items'];
    }

    public function testMethodAndCollectionKeyArePartOfTheFingerprint(): void
    {
        $get = RequestTarget::fromUrl('GET', 'https://api.example.com/items', 'key');

        self::assertSame($get->fingerprint, RequestTarget::fromUrl('get', 'https://api.example.com/items', 'key')->fingerprint);
        self::assertNotSame($get->fingerprint, RequestTarget::fromUrl('POST', 'https://api.example.com/items', 'key')->fingerprint);
        self::assertNotSame($get->fingerprint, RequestTarget::fromUrl('GET', 'https://api.example.com/items', 'other-key')->fingerprint);
    }

    #[DataProvider('invalidTargets')]
    public function testInvalidTargetsAreNeverDisplayedVerbatim(string $url): void
    {
        $target = RequestTarget::fromUrl('GET', $url, 'key', true);

        self::assertSame('[unknown target]', $target->displayUrl);
        self::assertNull($target->host);
        self::assertNull($target->path);
        self::assertStringNotContainsString('secret', serialize($target));
    }

    /** @return iterable<array{string}> */
    public static function invalidTargets(): iterable
    {
        yield ['/secret'];
        yield ['https://'];
        yield ['https://example.com:99999/secret'];
        yield ['file:///secret'];
    }
}
