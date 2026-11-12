<?php

declare(strict_types=1);

namespace Neox\BoardBundle\Tests\Unit;

use Neox\BoardBundle\Controller\DashboardController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class DashboardRefreshResponseTest extends TestCase
{
    public function testLocalRefreshStreamDoesNotReuseTheCompletedTurboRequestId(): void
    {
        $controller = (new ReflectionClass(DashboardController::class))->newInstanceWithoutConstructor();
        $response = (new ReflectionMethod(DashboardController::class, 'refreshResponse'))->invoke($controller);

        self::assertSame(
            '<turbo-stream action="refresh" method="morph" scroll="preserve"></turbo-stream>',
            $response->getContent(),
        );
    }
}
