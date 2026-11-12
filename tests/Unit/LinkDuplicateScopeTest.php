<?php
declare(strict_types=1);

namespace Neox\BoardBundle\Tests\Unit;

use Neox\BoardBundle\Controller\DashboardController;
use Neox\BoardBundle\Entity\{Board, Link, Space};
use PHPUnit\Framework\TestCase;

final class LinkDuplicateScopeTest extends TestCase
{
    public function testDuplicateIsFoundAcrossDifferentSpacesInTheSameBoard(): void
    {
        $board = new Board('owner');
        $firstSpace = new Space('First');
        $board->addSpace($firstSpace);
        $firstSpace->addLink(new Link('Existing', 'https://example.com/path/'));
        $secondSpace = new Space('Second');
        $board->addSpace($secondSpace);

        $controller = (new \ReflectionClass(DashboardController::class))->newInstanceWithoutConstructor();
        $exists = (new \ReflectionMethod(DashboardController::class, 'linkUrlExists'))
            ->invoke($controller, $board, 'https://EXAMPLE.com/path');

        self::assertTrue($exists);
    }
}
