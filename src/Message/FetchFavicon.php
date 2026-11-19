<?php
declare(strict_types=1);
namespace Neox\BoardBundle\Message;
final readonly class FetchFavicon
{
    public function __construct(public string $linkId) {}
}
