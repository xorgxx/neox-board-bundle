<?php
declare(strict_types=1);

namespace Neox\BoardBundle\Tests\Unit;

use Neox\BoardBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testSecureAndOptionalDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), []);

        self::assertTrue($config['enabled']);
        self::assertTrue($config['security']['require_authentication']);
        self::assertNull($config['security']['role']);
        self::assertFalse($config['realtime']['mercure_enabled']);
        self::assertFalse($config['realtime']['async_enabled']);
        self::assertNull($config['realtime']['messenger_bus']);
    }
}
