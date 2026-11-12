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
            $requestedUrl = null;
            $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requestedUrl): MockResponse {
                $requestedUrl = $url;
                self::assertSame('GET', $method);
                self::assertSame(0, $options['max_redirects']);
                return new MockResponse("\x00\x00\x01\x00icon", ['response_headers' => ['content-type: ']]);
            });
            $kernel = $this->createMock(KernelInterface::class);
            $kernel->method('getProjectDir')->willReturn($directory);
            $fetcher = new FaviconFetcher($kernel, $client);
            $link = new Link('Dockhand', 'http://192.168.2.17:3000/stacks');

            $fetcher->fetchFor($link);

            self::assertSame('http://192.168.2.17:3000/favicon.ico', $requestedUrl);
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
}
