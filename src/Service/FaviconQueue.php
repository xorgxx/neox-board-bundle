<?php
declare(strict_types=1);
namespace Neox\BoardBundle\Service;
use Neox\BoardBundle\Entity\Link;
use Neox\BoardBundle\Message\FetchFavicon;
use Symfony\Component\Messenger\MessageBusInterface;
final readonly class FaviconQueue
{
    public function __construct(private MessageBusInterface $bus) {}
    public function dispatch(Link $link): void { $this->bus->dispatch(new FetchFavicon($link->getId())); }
}
