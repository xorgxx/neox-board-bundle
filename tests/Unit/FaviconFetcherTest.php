<?php
declare(strict_types=1);

namespace Neox\BoardBundle\Tests\Unit;

use Neox\BoardBundle\Entity\Link;
use Neox\BoardBundle\Service\FaviconFetcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpKernel\KernelInterface;

final class FaviconFetcherTest extends TestCase
{
    public function testExplicitLanAddressCanProvideAFavicon(): void
    {
        $directory = sys_get_temp_dir().'/neox-favicon-'.bin2hex(random_bytes(6));
        mkdir($directory);
        try {
            $requestedUrls = [];
            $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requestedUrls): MockResponse {
                $requestedUrls[] = $url;
                self::assertSame('GET', $method);
                self::assertSame(0, $options['max_redirects']);
                $body = str_ends_with($url, '/favicon.ico') ? "\x00\x00\x01\x00icon" : '<html><head><title>Dockhand</title></head><body>Page</body></html>';
                return new MockResponse($body, ['response_headers' => ['content-type: '.(str_ends_with($url, '/favicon.ico') ? 'application/octet-stream' : 'text/html')]]);
            });
            $kernel = $this->createMock(KernelInterface::class);
            $kernel->method('getProjectDir')->willReturn($directory);
            $fetcher = new FaviconFetcher($kernel, $client);
            $link = new Link('Dockhand', 'http://192.168.2.17:3000/stacks');

            $fetcher->fetchFor($link);

            self::assertSame(['http://192.168.2.17:3000/stacks', 'http://192.168.2.17:3000/favicon.ico'], $requestedUrls);
            self::assertNotNull($link->getFaviconFilename());
            self::assertStringEndsWith('.ico', $link->getFaviconFilename());
            self::assertFileExists($fetcher->getPath($link->getFaviconFilename()));
        } finally {
            foreach (glob($directory.'/var/neox-board/favicons/*') ?: [] as $file) unlink($file);
            @rmdir($directory.'/var/neox-board/favicons');
            @rmdir($directory.'/var/neox-board');
            @rmdir($directory.'/var');
            rmdir($directory);
        }
    }

    public function testHtmlHeadIconWithRelativeSvgUrlIsFoundRegardlessOfDeclaredMimeType(): void
    {
        $directory = sys_get_temp_dir().'/neox-favicon-'.bin2hex(random_bytes(6));
        mkdir($directory);
        try {
            $requestedUrls = [];
            $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requestedUrls): MockResponse {
                $requestedUrls[] = $url;
                self::assertSame(0, $options['max_redirects']);
                if (str_ends_with($url, '/dashboard/')) {
                    return new MockResponse('<html><head><link rel="icon" type="application/unknown" href="../icons/brand.svg?v=2"></head></html>', ['response_headers' => ['content-type: text/html']]);
                }
                return new MockResponse('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><path fill="blue" d="M0 0"/></svg>', ['response_headers' => ['content-type: application/octet-stream']]);
            });
            $kernel = $this->createMock(KernelInterface::class);
            $kernel->method('getProjectDir')->willReturn($directory);
            $fetcher = new FaviconFetcher($kernel, $client);
            $link = new Link('Site', 'http://192.168.2.17:3000/dashboard/');

            $fetcher->fetchFor($link);

            self::assertSame(['http://192.168.2.17:3000/dashboard/', 'http://192.168.2.17:3000/icons/brand.svg?v=2'], $requestedUrls);
            self::assertNotNull($link->getFaviconFilename());
            self::assertStringEndsWith('.svg', $link->getFaviconFilename());
            self::assertStringNotContainsString('<script', (string) file_get_contents($fetcher->getPath($link->getFaviconFilename())));
            self::assertFileExists($fetcher->getPath($link->getFaviconFilename()));
        } finally {
            foreach (glob($directory.'/var/neox-board/favicons/*') ?: [] as $file) unlink($file);
            @rmdir($directory.'/var/neox-board/favicons');
            @rmdir($directory.'/var/neox-board');
            @rmdir($directory.'/var');
            rmdir($directory);
        }
    }

    public function testHttpLinkHeaderCanAdvertisePngIcon(): void
    {
        $directory = sys_get_temp_dir().'/neox-favicon-'.bin2hex(random_bytes(6));
        mkdir($directory);
        try {
            $requestedUrls = [];
            $png = "\x89PNG\r\n\x1a\n".str_repeat("\0", 16);
            $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requestedUrls, $png): MockResponse {
                $requestedUrls[] = $url;
                if (str_ends_with($url, '/')) {
                    return new MockResponse('<html><head></head><body>Page</body></html>', ['response_headers' => ['content-type: text/html', 'link: </media/icon.png>; rel="icon"; type="image/png"']]);
                }
                return new MockResponse($png, ['response_headers' => ['content-type: application/octet-stream']]);
            });
            $kernel = $this->createMock(KernelInterface::class);
            $kernel->method('getProjectDir')->willReturn($directory);
            $fetcher = new FaviconFetcher($kernel, $client);
            $link = new Link('Site', 'http://192.168.2.17/');

            $fetcher->fetchFor($link);

            self::assertSame(['http://192.168.2.17/', 'http://192.168.2.17/media/icon.png'], $requestedUrls);
            self::assertNotNull($link->getFaviconFilename());
            self::assertStringEndsWith('.png', $link->getFaviconFilename());
            self::assertFileExists($fetcher->getPath($link->getFaviconFilename()));
        } finally {
            foreach (glob($directory.'/var/neox-board/favicons/*') ?: [] as $file) unlink($file);
            @rmdir($directory.'/var/neox-board/favicons');
            @rmdir($directory.'/var/neox-board');
            @rmdir($directory.'/var');
            rmdir($directory);
        }
    }
}
