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
            return new MockResponse('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><path d="M0 0"/></svg>');
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
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC');
        $client = new MockHttpClient(static fn (string $method, string $url): MockResponse => str_ends_with($url, '/page')
            ? new MockResponse('<link rel="icon" href="/icon.png">')
            : new MockResponse($png));
        $link = new Link('Site', 'http://192.168.2.17/page');

        (new FaviconFetcher($client))->fetchFor($link);

        $webp = (string) base64_decode((string) $link->getFaviconData(), true);
        self::assertStringStartsWith('RIFF', $webp);
        self::assertSame(25, unpack('V', substr($webp, 24, 4))[1] ?? null);
        self::assertSame(25, unpack('V', substr($webp, 28, 4))[1] ?? null);
    }
}
