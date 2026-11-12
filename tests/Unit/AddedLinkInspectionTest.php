<?php

declare(strict_types=1);

namespace Neox\BoardBundle\Tests\Unit;

use Neox\BoardBundle\Controller\DashboardController;
use Neox\BoardBundle\Entity\Link;
use Neox\BoardBundle\Service\FaviconFetcher;
use Neox\BoardBundle\Service\LinkAvailabilityChecker;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpKernel\KernelInterface;

final class AddedLinkInspectionTest extends TestCase
{
    public function testAddingOneLinkImmediatelyChecksItsAvailabilityAndFavicon(): void
    {
        $directory = sys_get_temp_dir().'/neox-added-link-'.bin2hex(random_bytes(6));
        mkdir($directory);
        try {
            $faviconClient = new MockHttpClient(static function (string $method, string $url): MockResponse {
                if ($url === 'http://192.168.2.17/dashboard') {
                    return new MockResponse('<html><head><link rel="icon" href="/custom/icon.png"></head></html>', ['response_headers' => ['content-type: text/html']]);
                }
                self::assertSame('http://192.168.2.17/custom/icon.png', $url);
                return new MockResponse("\x89PNG\r\n\x1a\nicon", ['response_headers' => ['content-type: image/png']]);
            });
            $kernel = $this->createMock(KernelInterface::class);
            $kernel->method('getProjectDir')->willReturn($directory);
            $fetcher = new FaviconFetcher($kernel, $faviconClient);
            $controller = (new \ReflectionClass(DashboardController::class))->newInstanceWithoutConstructor();
            (new ReflectionProperty(DashboardController::class, 'favicons'))->setValue($controller, $fetcher);
            $checker = new LinkAvailabilityChecker(new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 200])));
            $link = new Link('Internal dashboard', 'http://192.168.2.17/dashboard');

            (new ReflectionMethod(DashboardController::class, 'inspectAddedLink'))->invoke($controller, $link, $checker);

            self::assertNotNull($link->getFaviconFilename());
            self::assertStringEndsWith('.png', $link->getFaviconFilename());
            self::assertTrue($link->getAvailabilityStatus());
            self::assertNotNull($link->getAvailabilityCheckedAt());
        } finally {
            foreach (glob($directory.'/var/neox-board/favicons/*') ?: [] as $file) unlink($file);
            @rmdir($directory.'/var/neox-board/favicons');
            @rmdir($directory.'/var/neox-board');
            @rmdir($directory.'/var');
            rmdir($directory);
        }
    }
}
