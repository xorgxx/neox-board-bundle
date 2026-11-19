<?php
declare(strict_types=1);
namespace Neox\BoardBundle\Tests\Unit;
use Neox\BoardBundle\DependencyInjection\NeoxBoardExtension;
use Neox\BoardBundle\Message\FetchFavicon;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
final class FaviconMessengerRoutingTest extends TestCase
{
    public function testFaviconJobsUseTheHostAsyncWorkerTransport(): void
    {
        $container = new ContainerBuilder();
        (new NeoxBoardExtension())->prepend($container);
        $framework = $container->getExtensionConfig('framework')[0];
        self::assertSame('async', $framework['messenger']['routing'][FetchFavicon::class]);
        self::assertArrayNotHasKey('neox_board_favicon', $framework['messenger']['transports'] ?? []);
    }
}
