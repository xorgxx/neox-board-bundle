<?php
declare(strict_types=1);

namespace Neox\BoardBundle\Tests\Unit;

use Neox\BoardBundle\Entity\{Board, Link, Space, Widget};
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class EntityValidationTest extends TestCase
{
    public function testLinkRequiresValidHttpUrlAndTitle(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $link = new Link('', 'javascript:alert(1)');

        self::assertGreaterThan(0, count($validator->validate($link)));

        $valid = new Link('Documentation', 'https://example.test/docs');
        self::assertCount(0, $validator->validate($valid));
    }

    public function testWidgetTypeMustBeAValidIdentifier(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $space = new Space();
        $board = new Board('test-owner');
        $board->addSpace($space);

        $widget = new Widget($space, 'not valid!', 'Widget');
        self::assertGreaterThan(0, count($validator->validate($widget)));

        $widget->setType('recent_links');
        self::assertCount(0, $validator->validate($widget));
    }
}
