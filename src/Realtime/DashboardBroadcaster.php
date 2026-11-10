<?php
declare(strict_types=1);
namespace Neox\BoardBundle\Realtime;
use Neox\BoardBundle\Entity\Board;
interface DashboardBroadcaster
{
    public function publishRefresh(Board $board, ?string $requestId = null): void;
    public function subscriberUrl(Board $board): ?string;
}
