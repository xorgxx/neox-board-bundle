<?php
declare(strict_types=1);
namespace Neox\BoardBundle\Tests\Unit;
use Neox\BoardBundle\Entity\Link;
use Neox\BoardBundle\Message\FetchFavicon;
use Neox\BoardBundle\Service\FaviconQueue;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
final class FaviconQueueTest extends TestCase
{
    public function testItDispatchesOnlyThePersistedLinkIdentifier(): void
    {
        $link = new Link('Site', 'https://example.test');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with(self::callback(fn (FetchFavicon $message): bool => $message->linkId === $link->getId()))->willReturnCallback(static fn ($message) => new Envelope($message));
        (new FaviconQueue($bus))->dispatch($link);
    }
}
