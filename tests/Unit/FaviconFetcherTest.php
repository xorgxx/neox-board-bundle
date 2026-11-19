<?php

declare(strict_types=1);

namespace Neox\BoardBundle\Tests\Unit;

use Neox\BoardBundle\Entity\Link;
use Neox\BoardBundle\Service\FaviconFetcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class FaviconFetcherTest extends TestCase
{
    public function testSvgFaviconIsSanitizedAndStoredInTheLink(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url): MockResponse {
            if (str_ends_with($url, '/page')) return new MockResponse('<link rel="icon" href="/brand.svg">');
            return new MockResponse('<svg xmlns="http://www.w3.org/2000/svg" width="537" height="537"><script>alert(1)</script><path d="M0 0"/></svg>');
        });
        $link = new Link('Site', 'http://192.168.2.17/page');

        (new FaviconFetcher($client))->fetchFor($link);

        self::assertNotNull($link->getFaviconData());
        $svg = (string) base64_decode($link->getFaviconData(), true);
        self::assertStringNotContainsString('<script', $svg);
        self::assertStringContainsString('width="25"', $svg);
        self::assertStringContainsString('height="25"', $svg);
    }

    public function testRasterFaviconIsStoredAsTwentyFivePixelWebp(): void
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) self::markTestSkipped('GD with WebP support is required.');
        $png = $this->pngFixture();
        $client = new MockHttpClient(static fn (string $method, string $url): MockResponse => str_ends_with($url, '/page')
            ? new MockResponse('<link rel="icon" href="/icon.png">')
            : new MockResponse($png));
        $link = new Link('Site', 'http://192.168.2.17/page');

        (new FaviconFetcher($client))->fetchFor($link);

        $webp = (string) base64_decode((string) $link->getFaviconData(), true);
        self::assertStringStartsWith('RIFF', $webp);
        $size = getimagesizefromstring($webp);
        self::assertSame([25, 25], [$size[0] ?? null, $size[1] ?? null]);
    }

    public function testPngEmbeddedInIcoIsConvertedToAThumbnail(): void
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) self::markTestSkipped('GD with WebP support is required.');
        $png = $this->pngFixture();
        $ico = pack('vvv', 0, 1, 1).pack('CCCCvvVV', 2, 2, 0, 0, 1, 32, strlen($png), 22).$png;
        $client = new MockHttpClient(static fn (string $method, string $url): MockResponse => str_ends_with($url, '/page')
            ? new MockResponse('<link rel="icon" href="/favicon.ico">')
            : new MockResponse($ico));
        $link = new Link('Site', 'http://192.168.2.17/page');

        (new FaviconFetcher($client))->fetchFor($link);

        $webp = (string) base64_decode((string) $link->getFaviconData(), true);
        self::assertStringStartsWith('RIFF', $webp);
        $size = getimagesizefromstring($webp);
        self::assertSame([25, 25], [$size[0] ?? null, $size[1] ?? null]);
    }

    public function testWipHostUsesConfiguredInternalProxyAndFollowsOnlySameHostRedirects(): void
    {
        $previousProxyHost = getenv('NEOX_BOARD_INTERNAL_PROXY_HOST');
        putenv('NEOX_BOARD_INTERNAL_PROXY_HOST=192.168.2.17');
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = $url;
            if ($url === 'http://example.wip/page') return new MockResponse('', ['http_code' => 301, 'response_headers' => ['Location: https://example.wip/page']]);
            if ($url === 'https://example.wip/page') return new MockResponse('<html><head><link rel="icon" href="/icon.svg"></head></html>');
            if ($url === 'http://example.wip/icon.svg') return new MockResponse('<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>');
            return new MockResponse('', ['http_code' => 404]);
        });

        try {
            $link = new Link('Local', 'http://example.wip/page');
            (new FaviconFetcher($client))->fetchFor($link);

            self::assertNotNull($link->getFaviconData());
            self::assertSame([
                'http://example.wip/page',
                'https://example.wip/page',
                'http://example.wip/icon.svg',
            ], $requests);
        } finally {
            if ($previousProxyHost === false) putenv('NEOX_BOARD_INTERNAL_PROXY_HOST');
            else putenv('NEOX_BOARD_INTERNAL_PROXY_HOST='.$previousProxyHost);
        }
    }

    public function testDeepLinkFallsBackToTheInternalDomainRootForItsFavicon(): void
    {
        $previousProxyHost = getenv('NEOX_BOARD_INTERNAL_PROXY_HOST');
        putenv('NEOX_BOARD_INTERNAL_PROXY_HOST=192.168.2.17');
        try {
            $client = new MockHttpClient(static function (string $method, string $url): MockResponse {
                if ($url === 'https://example.wip/secure/page') return new MockResponse('', ['http_code' => 404]);
                if ($url === 'https://example.wip/') return new MockResponse('<html><head><link rel="icon" href="/favicon.svg"></head></html>');
                return new MockResponse('<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>');
            });
            $link = new Link('Local', 'https://example.wip/secure/page');

            (new FaviconFetcher($client))->fetchFor($link);

            self::assertNotNull($link->getFaviconData());
        } finally {
            if ($previousProxyHost === false) putenv('NEOX_BOARD_INTERNAL_PROXY_HOST');
            else putenv('NEOX_BOARD_INTERNAL_PROXY_HOST='.$previousProxyHost);
        }
    }

    private function pngFixture(): string
    {
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);
        return $png;
    }
}
