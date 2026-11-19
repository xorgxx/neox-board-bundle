<?php
declare(strict_types=1);
namespace Neox\BoardBundle\Command;
use Doctrine\ORM\EntityManagerInterface;
use Neox\BoardBundle\Entity\Link;
use Neox\BoardBundle\Service\FaviconQueue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
#[AsCommand(name: 'neox-board:favicons:enqueue-missing', description: 'Met en file les favicons manquants du dashboard.')]
final class EnqueueMissingFaviconsCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly FaviconQueue $queue)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $links = $this->em->getRepository(Link::class)->findBy(['faviconData' => null]);
        foreach ($links as $link) $this->queue->dispatch($link);
        $io->success(sprintf('%d favicon%s mise%s en file sur le transport async.', count($links), count($links) === 1 ? '' : 's', count($links) === 1 ? '' : 's'));
        return Command::SUCCESS;
    }
}
