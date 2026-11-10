<?php

declare(strict_types=1);

namespace Neox\BoardBundle\Service;

use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WeatherForecastProvider
{
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly HttpClientInterface $client,
        private readonly float $latitude,
        private readonly float $longitude,
        private readonly string $timezone,
        private readonly string $location,
        private readonly bool $enabled,
        private readonly string $apiUrl,
        private readonly ?string $apiKey,
        private readonly string $schoolCity,
        private readonly string $schoolZone,
    ) {
    }

    /** @return array{location:string,days:list<array{date:string,label:string,icon:string,condition:string,min:int,max:int,current:?int}>} */
    public function getForecast(?array $location = null): array
    {
        if (!$this->enabled) {
            return [];
        }

        $location ??= $this->getDefaultLocation();
        $latitude = (float) $location['latitude'];
        $longitude = (float) $location['longitude'];
        $timezone = (string) $location['timezone'];
        $locationName = (string) $location['name'];

        $cacheKey = substr(hash('sha256', $latitude.'|'.$longitude.'|'.$timezone), 0, 16);
        $cachePath = $this->kernel->getProjectDir().'/var/neox-board/weather-'.$cacheKey.'.json';
        if (is_file($cachePath) && (filemtime($cachePath) ?: 0) > time() - 1800) {
            $cached = json_decode((string) @file_get_contents($cachePath), true);
            if (is_array($cached) && isset($cached['days']) && count($cached['days']) === 5) {
                return $cached;
            }
        }

        try {
            $query = [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'current' => 'temperature_2m,weather_code',
                    'daily' => 'weather_code,temperature_2m_max,temperature_2m_min',
                    'forecast_days' => 5,
                    'timezone' => $timezone,
                    'temperature_unit' => 'celsius',
                ];
            if ($this->apiKey !== null && $this->apiKey !== '') {
                $query['apikey'] = $this->apiKey;
            }
            $response = $this->client->request('GET', $this->apiUrl, [
                'query' => $query,
                'timeout' => 2.0,
                'max_duration' => 3.0,
                'max_redirects' => 0,
            ]);
            if ($response->getStatusCode() !== 200) {
                $response->cancel();
                return [];
            }
            $payload = $response->toArray();
            $daily = $payload['daily'] ?? [];
            $dates = $daily['time'] ?? [];
            $maxima = $daily['temperature_2m_max'] ?? [];
            $minima = $daily['temperature_2m_min'] ?? [];
            $codes = $daily['weather_code'] ?? [];
            if (count($dates) < 5 || count($maxima) < 5 || count($minima) < 5 || count($codes) < 5) {
                return [];
            }

            $days = [];
            $weekdays = [1 => 'lun.', 2 => 'mar.', 3 => 'mer.', 4 => 'jeu.', 5 => 'ven.', 6 => 'sam.', 7 => 'dim.'];
            foreach (array_slice($dates, 0, 5) as $index => $date) {
                $code = (int) $codes[$index];
                [$condition, $icon] = $this->describeCode($code);
                $days[] = [
                    'date' => (string) $date,
                    'label' => $index === 0 ? 'Aujourd’hui' : $weekdays[(int) (new \DateTimeImmutable((string) $date))->format('N')],
                    'icon' => $icon,
                    'condition' => $condition,
                    'min' => (int) round((float) $minima[$index]),
                    'max' => (int) round((float) $maxima[$index]),
                    'current' => $index === 0 && isset($payload['current']['temperature_2m'])
                        ? (int) round((float) $payload['current']['temperature_2m'])
                        : null,
                ];
            }

            $forecast = ['location' => $locationName, 'days' => $days];
            $directory = dirname($cachePath);
            if ((!is_dir($directory) && @mkdir($directory, 0770, true)) || is_dir($directory)) {
                $temporaryPath = $cachePath.'.'.bin2hex(random_bytes(4)).'.tmp';
                if (@file_put_contents($temporaryPath, json_encode($forecast, JSON_UNESCAPED_UNICODE), LOCK_EX) !== false) {
                    @rename($temporaryPath, $cachePath);
                }
            }

            return $forecast;
        } catch (\Throwable) {
            return [];
        }
    }

    public function getTimezone(?array $location = null): string
    {
        return (string) ($location['timezone'] ?? $this->timezone);
    }

    public function getDefaultLocation(): array
    {
        return [
            'name' => $this->location,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'timezone' => $this->timezone,
            'country_code' => 'FR',
            'school_city' => $this->schoolCity,
            'school_zone' => $this->schoolZone,
        ];
    }

    public function geocode(string $query): ?array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2 || mb_strlen($query) > 100) {
            return null;
        }

        try {
            $response = $this->client->request('GET', 'https://geocoding-api.open-meteo.com/v1/search', [
                'query' => ['name' => $query, 'count' => 1, 'language' => 'fr', 'format' => 'json'],
                'timeout' => 2.5,
                'max_duration' => 3,
                'max_redirects' => 0,
            ]);
            if ($response->getStatusCode() !== 200) {
                $response->cancel();
                return null;
            }
            $result = $response->toArray(false)['results'][0] ?? null;
            if (!is_array($result) || !isset($result['name'], $result['latitude'], $result['longitude'], $result['timezone'])) {
                return null;
            }

            $latitude = (float) $result['latitude'];
            $longitude = (float) $result['longitude'];
            $timezone = (string) $result['timezone'];
            if (!is_finite($latitude) || $latitude < -90 || $latitude > 90 || !is_finite($longitude) || $longitude < -180 || $longitude > 180) {
                return null;
            }
            new \DateTimeZone($timezone);

            $schoolLocations = [
                'lyon' => ['Lyon', 'Zone A'], 'paris' => ['Paris', 'Zone C'],
                'marseille' => ['Aix-Marseille', 'Zone B'], 'bordeaux' => ['Bordeaux', 'Zone A'],
                'lille' => ['Lille', 'Zone B'], 'nantes' => ['Nantes', 'Zone B'],
                'strasbourg' => ['Strasbourg', 'Zone B'], 'toulouse' => ['Toulouse', 'Zone C'],
                'nice' => ['Nice', 'Zone B'], 'rennes' => ['Rennes', 'Zone B'],
                'grenoble' => ['Grenoble', 'Zone A'], 'dijon' => ['Dijon', 'Zone A'],
                'clermont-ferrand' => ['Clermont-Ferrand', 'Zone A'], 'poitiers' => ['Poitiers', 'Zone A'],
                'limoges' => ['Limoges', 'Zone A'], 'besançon' => ['Besançon', 'Zone A'],
                'reims' => ['Reims', 'Zone B'], 'amiens' => ['Amiens', 'Zone B'],
                'rouen' => ['Normandie', 'Zone B'], 'caen' => ['Normandie', 'Zone B'],
                'montpellier' => ['Montpellier', 'Zone C'], 'orleans' => ['Orléans-Tours', 'Zone B'],
                'tours' => ['Orléans-Tours', 'Zone B'], 'nancy' => ['Nancy-Metz', 'Zone B'],
                'metz' => ['Nancy-Metz', 'Zone B'], 'aix-en-provence' => ['Aix-Marseille', 'Zone B'],
            ];
            $schoolCalendar = ($result['country_code'] ?? null) === 'FR'
                ? ($schoolLocations[mb_strtolower((string) $result['name'])] ?? null)
                : null;

            return [
                'name' => (string) $result['name'],
                'latitude' => $latitude,
                'longitude' => $longitude,
                'timezone' => $timezone,
                'country_code' => (string) ($result['country_code'] ?? ''),
                'school_city' => $schoolCalendar[0] ?? null,
                'school_zone' => $schoolCalendar[1] ?? null,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{string,string} */
    private function describeCode(int $code): array
    {
        return match (true) {
            $code === 0 => ['Ciel dégagé', '☀️'],
            $code <= 2 => ['Peu nuageux', '🌤️'],
            $code === 3 => ['Couvert', '☁️'],
            in_array($code, [45, 48], true) => ['Brouillard', '🌫️'],
            in_array($code, [51, 53, 55, 56, 57], true) => ['Bruine', '🌦️'],
            in_array($code, [61, 63, 65, 66, 67], true) => ['Pluie', '🌧️'],
            in_array($code, [71, 73, 75, 77, 85, 86], true) => ['Neige', '🌨️'],
            in_array($code, [80, 81, 82], true) => ['Averses', '🌦️'],
            in_array($code, [95, 96, 99], true) => ['Orage', '⛈️'],
            default => ['Conditions variables', '🌥️'],
        };
    }
}
