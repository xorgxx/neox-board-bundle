<?php

declare(strict_types=1);

namespace Neox\BoardBundle\Tests\Unit;

use Neox\BoardBundle\Controller\DashboardController;
use Neox\BoardBundle\Entity\Link;
use Neox\BoardBundle\Service\LinkAvailabilityChecker;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AddedLinkInspectionTest extends TestCase
{
    public function testNewLinkAvailabilityCheckDoesNotWaitForTheFaviconWorker(): void
    {
        $controller = (new \ReflectionClass(DashboardController::class))->newInstanceWithoutConstructor();
        $checker = new LinkAvailabilityChecker(new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 200])));
        $link = new Link('Site', 'https://example.test');

        (new ReflectionMethod(DashboardController::class, 'inspectAddedLink'))->invoke($controller, $link, $checker);

        self::assertTrue($link->getAvailabilityStatus());
        self::assertNotNull($link->getAvailabilityCheckedAt());
        self::assertNull($link->getFaviconData(), 'the favicon is populated later by Messenger');
    }
}
