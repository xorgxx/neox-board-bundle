<?php

declare(strict_types=1);

namespace Neox\BoardBundle\Tests\Unit;

use Neox\BoardBundle\Entity\Link;
use Neox\BoardBundle\Service\LinkAvailabilityChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LinkAvailabilityCheckerTest extends TestCase
{
    public function testRetriesMethodSpecificHeadErrorsWithAOneByteGet(): void
    {
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $range = array_values(array_filter($options['headers'], static fn (string $header): bool => str_starts_with(strtolower($header), 'range:')))[0] ?? null;
            $requests[] = [$method, $url, $range];
            return new MockResponse('', ['http_code' => $method === 'HEAD' ? 404 : 200]);
        });
        $link = new Link('A working page', 'https://example.test/page');

        $results = (new LinkAvailabilityChecker($client))->check([$link]);

        self::assertSame([
            ['HEAD', 'https://example.test/page', null],
            ['GET', 'https://example.test/page', 'Range: bytes=0-0'],
        ], $requests);
        self::assertTrue($results[0]['active']);
        self::assertSame('Actif', $results[0]['label']);
    }

    public function testARealMissingPageStaysInactiveAfterGetFallback(): void
    {
        $client = new MockHttpClient(static fn (string $method): MockResponse => new MockResponse('', ['http_code' => 404]));
        $results = (new LinkAvailabilityChecker($client))->check([new Link('Removed page', 'https://example.test/removed')]);

        self::assertFalse($results[0]['active']);
        self::assertSame('missing', $results[0]['state']);
    }

    public function testRateLimitedLinkIsStillReportedAsActive(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 429]));
        $results = (new LinkAvailabilityChecker($client))->check([new Link('Rate limited', 'https://example.test/')]);

        self::assertTrue($results[0]['active']);
        self::assertSame('restricted', $results[0]['state']);
        self::assertSame('Actif (limite de requêtes)', $results[0]['label']);
    }
}
