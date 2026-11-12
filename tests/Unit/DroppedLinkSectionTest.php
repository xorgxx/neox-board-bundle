<?php
declare(strict_types=1);

namespace Neox\BoardBundle\Tests\Unit;

use Neox\BoardBundle\Controller\DashboardController;
use Neox\BoardBundle\Entity\{Link, Section, Space};
use PHPUnit\Framework\TestCase;

final class DroppedLinkSectionTest extends TestCase
{
    public function testLinkDroppedOnFirstSectionStaysBeforeTheNextSection(): void
    {
        $space = new Space('Links');
        $first = new Section($space, 'First');
        $first->setPosition(0);
        $space->addSection($first);
        $existing = new Link('Existing', 'https://example.com');
        $existing->setPosition(1);
        $space->addLink($existing);
        $second = new Section($space, 'Second');
        $second->setPosition(2);
        $space->addSection($second);
        $later = new Link('Later', 'https://example.org');
        $later->setPosition(3);
        $space->addLink($later);

        $dropped = new Link('Dropped', 'https://example.net');
        $controller = (new \ReflectionClass(DashboardController::class))->newInstanceWithoutConstructor();
        (new \ReflectionMethod(DashboardController::class, 'positionDroppedLink'))->invoke($controller, $space, $dropped, $first->getId());
        $space->addLink($dropped);

        $items = [...$space->getSections(), ...$space->getLinks()];
        usort($items, static fn ($a, $b) => $a->getPosition() <=> $b->getPosition());
        self::assertSame([$first, $existing, $dropped, $second, $later], $items);
    }
}
