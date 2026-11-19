<?php
declare(strict_types=1);
namespace Neox\BoardBundle\MessageHandler;
use Doctrine\ORM\EntityManagerInterface;
use Neox\BoardBundle\Entity\Link;
use Neox\BoardBundle\Message\FetchFavicon;
use Neox\BoardBundle\Service\FaviconFetcher;
use Neox\BoardBundle\Realtime\DashboardBroadcaster;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
#[AsMessageHandler]
final readonly class FetchFaviconHandler
{
    public function __construct(private EntityManagerInterface $em, private FaviconFetcher $fetcher, private DashboardBroadcaster $broadcaster) {}
    public function __invoke(FetchFavicon $message): void
    {
        $link = $this->em->getRepository(Link::class)->find($message->linkId);
        if (!$link || $link->getFaviconData() !== null) return;
        $this->fetcher->fetchFor($link);
        $created = $link->getFaviconData() !== null;
        $this->em->flush();
        if ($created) $this->broadcaster->publishRefresh($link->getSpace()->getBoard(), bin2hex(random_bytes(16)));
    }
}
