<?php

declare(strict_types=1);

namespace Neox\BoardBundle\Service;

use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class CalendarObservancesProvider
{
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly HttpClientInterface $client,
        private readonly string $timezone,
        private readonly string $city,
        private readonly string $schoolZone,
        private readonly bool $enabled,
    ) {
    }

    /** @return array{saint:?array{name:string,url:string},events:list<array{label:string,url:string}>} */
    public function getForDate(\DateTimeImmutable $date, ?string $city = null, ?string $schoolZone = null, string $countryCode = 'FR', ?string $timezone = null): array
    {
        $empty = ['saint' => null, 'events' => []];
        if (!$this->enabled) {
            return $empty;
        }

        $date = $date->setTimezone(new \DateTimeZone($timezone ?? $this->timezone));
        $city ??= $this->city;
        $schoolZone ??= $this->schoolZone;
        $cacheKey = hash('sha256', 'calendar-v2|'.$date->format('Y-m-d').'|'.$city.'|'.$schoolZone.'|'.$countryCode);
        $cachePath = $this->kernel->getProjectDir().'/var/neox-board/calendar-'.$cacheKey.'.json';
        if (is_file($cachePath)) {
            $cached = json_decode((string) @file_get_contents($cachePath), true);
            if (is_array($cached) && array_key_exists('saint', $cached) && isset($cached['events'])) {
                $hasData = $cached['saint'] !== null || $cached['events'] !== [];
                $cacheLifetime = $hasData ? 21600 : 300;
                if ((filemtime($cachePath) ?: 0) > time() - $cacheLifetime) {
                    return $cached;
                }
            }
        }

        $schoolYearStart = (int) $date->format('Y') - ((int) $date->format('n') < 9 ? 1 : 0);
        $schoolYear = $schoolYearStart.'-'.($schoolYearStart + 1);
        $saintResponse = $this->requestJson('https://nominis.cef.fr/json/nominis.php', [
                'query' => ['jour' => $date->format('j'), 'mois' => $date->format('n'), 'annee' => $date->format('Y')],
        ]);
        $holidayResponse = $countryCode === 'FR'
            ? $this->requestJson('https://calendrier.api.gouv.fr/jours-feries/metropole/'.$date->format('Y').'.json')
            : [];
        $schoolResponse = $city !== '' && $schoolZone !== ''
            ? $this->requestJson('https://data.education.gouv.fr/api/explore/v2.1/catalog/datasets/fr-en-calendrier-scolaire/records', [
                'query' => [
                    'where' => sprintf('location="%s" AND zones="%s" AND annee_scolaire="%s"', $city, $schoolZone, $schoolYear),
                    'limit' => 100,
                ],
            ])
            : null;
        $saintPayload = $saintResponse !== null ? $this->readJson($saintResponse) : [];
        $holidayPayload = $holidayResponse instanceof ResponseInterface ? $this->readJson($holidayResponse) : [];
        $schoolPayload = $schoolResponse !== null ? $this->readJson($schoolResponse) : [];

        $saint = null;
        $majorSaints = $saintPayload['response']['saints']['majeurs'] ?? [];
        if (is_array($majorSaints) && $majorSaints !== []) {
            $firstSaint = reset($majorSaints);
            $name = is_array($firstSaint) ? (string) ($firstSaint['valeur'] ?? array_key_first($majorSaints)) : (string) array_key_first($majorSaints);
            if ($name !== '') {
                $saint = ['name' => $name, 'url' => 'https://nominis.cef.fr/'];
            }
        }

        $events = [];
        $dateKey = $date->format('Y-m-d');
        if (isset($holidayPayload[$dateKey]) && is_string($holidayPayload[$dateKey])) {
            $events[] = ['label' => 'Jour férié · '.$holidayPayload[$dateKey], 'url' => 'https://calendrier.api.gouv.fr/'];
        }

        foreach (($schoolPayload['results'] ?? []) as $period) {
            if (!is_array($period) || !isset($period['start_date'], $period['end_date'], $period['description'])) {
                continue;
            }
            if (isset($period['population']) && !in_array($period['population'], ['-', 'Élèves'], true)) {
                continue;
            }
            try {
                $start = new \DateTimeImmutable((string) $period['start_date']);
                $end = new \DateTimeImmutable((string) $period['end_date']);
                if ($date >= $start && $date < $end) {
                    $events[] = ['label' => (string) $period['description'], 'url' => 'https://data.education.gouv.fr/explore/dataset/fr-en-calendrier-scolaire/'];
                    break;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $this->store($cachePath, ['saint' => $saint, 'events' => $events]);
    }

    private function readJson(ResponseInterface $response): array
    {
        try {
            if ($response->getStatusCode() !== 200) {
                $response->cancel();
                return [];
            }
            $payload = $response->toArray(false);
            return is_array($payload) ? $payload : [];
        } catch (\Throwable) {
            $response->cancel();
            return [];
        }
    }

    private function requestJson(string $url, array $options = []): ?ResponseInterface
    {
        try {
            return $this->client->request('GET', $url, $options + [
                'timeout' => 2.5,
                'max_duration' => 3,
                'max_redirects' => 0,
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    private function store(string $cachePath, array $data): array
    {
        $directory = dirname($cachePath);
        if ((!is_dir($directory) && @mkdir($directory, 0770, true)) || is_dir($directory)) {
            $temporaryPath = $cachePath.'.'.bin2hex(random_bytes(4)).'.tmp';
            if (@file_put_contents($temporaryPath, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX) !== false) {
                @rename($temporaryPath, $cachePath);
            }
        }
        return $data;
    }
}
