<?php
declare(strict_types=1);

namespace Neox\BoardBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Neox\BoardBundle\Entity\{Board, Space};
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class DashboardTemplateTest extends TestCase
{
    public function testDashboardTemplateRendersSpacesInTheirOwnColumns(): void
    {
        $loader = new FilesystemLoader(dirname(__DIR__, 2).'/templates');
        $loader->addPath(dirname(__DIR__, 2).'/templates', 'NeoxBoard');
        $twig = new Environment($loader);
        $twig->addFunction(new TwigFunction('asset', static fn (string $path): string => '/'.$path));
        $twig->addFunction(new TwigFunction('path', static fn (string $route, array $params = []): string => '/'.$route));
        $twig->addFunction(new TwigFunction('csrf_token', static fn (string $id): string => 'test-token-'.$id));

        $board = new Board('camille@example.test');
        $leftSpace = new Space('Colonne gauche');
        $leftSpace->setColumnIndex(0);
        $board->addSpace($leftSpace);
        $rightSpace = new Space('Colonne droite');
        $rightSpace->setColumnIndex(1);
        $board->addSpace($rightSpace);

        $html = $twig->render('dashboard/index.html.twig', [
            'user_name' => 'Camille',
            'spaces' => [$leftSpace, $rightSpace],
            'display_spaces' => [$leftSpace, $rightSpace],
            'menu_items' => [$leftSpace->getId() => [], $rightSpace->getId() => []],
            'search_space_ids' => [],
            'search_link_ids' => [],
            'activities' => [],
            'links_count' => 0,
            'favorites_count' => 0,
            'query' => '',
            'panel' => 'overview',
            'mercure_url' => null,
            'weather_forecast' => ['days' => []],
            'date_label' => 'mardi 10 novembre',
            'calendar_observances' => ['events' => []],
        ]);

        self::assertStringContainsString('Mes espaces', $html);
        self::assertStringContainsString('Colonne gauche', $html);
        self::assertStringContainsString('Colonne droite', $html);
        $leftColumn = strpos($html, 'data-space-column="0"');
        $rightColumn = strpos($html, 'data-space-column="1"');
        self::assertNotFalse($leftColumn);
        self::assertNotFalse($rightColumn);
        self::assertLessThan(strpos($html, 'data-space-order-id="'.$leftSpace->getId().'"'), $leftColumn);
        self::assertLessThan(strpos($html, 'data-space-order-id="'.$rightSpace->getId().'"'), $rightColumn);
        self::assertLessThan($rightColumn, strpos($html, 'data-space-order-id="'.$leftSpace->getId().'"'));
        self::assertStringContainsString('dashboard.css', $html);
    }
}
