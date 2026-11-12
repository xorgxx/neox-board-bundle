<?php

declare(strict_types=1);

namespace Neox\BoardBundle\Service;

use Neox\BoardBundle\Entity\Link;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Checks saved HTTP links, including local development hosts. */
final class LinkAvailabilityChecker
{
    public function __construct(private readonly HttpClientInterface $client)
    {
    }

    /** @param list<Link> $links @return list<array{id:string,title:string,url:string,state:string,label:string,active:bool}> */
    public function check(array $links): array
    {
        $results = [];
        $hostGateway = gethostbyname('host.docker.internal');

        foreach ($links as $key => $link) {
            $url = $link->getUrl();
            $parts = parse_url($url);
            if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) {
                $results[$key] = $this->result($link, 'unsupported', 'Adresse non vérifiable', false);
                continue;
            }

            $options = [
                'max_redirects' => 8,
                'timeout' => 1.5,
                'max_duration' => 2.0,
                'headers' => [
                    'Accept' => 'text/html,application/xhtml+xml,application/json,*/*;q=0.8',
                    'User-Agent' => 'Mozilla/5.0 (compatible; NeoxBoardLinkChecker/1.0)',
                ],
            ];
            $host = strtolower((string) $parts['host']);
            if (str_ends_with($host, '.wip') && $hostGateway !== 'host.docker.internal' && filter_var($hostGateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $options['resolve'][$host] = $hostGateway;
                $options['verify_peer'] = false;
            }

            try {
                $status = $this->requestStatus('HEAD', $url, $options);
                // Some servers reject HEAD or report a method-specific 404. Retry
                // with a one-byte range so the link itself, rather than HEAD support,
                // determines the result.
                if (in_array($status, [400, 403, 404, 405, 410, 501], true)) {
                    $getOptions = $options;
                    $getOptions['headers']['Range'] = 'bytes=0-0';
                    $status = $this->requestStatus('GET', $url, $getOptions);
                }

                [$state, $label, $active] = match (true) {
                    $status >= 200 && $status < 300 => ['active', 'Actif', true],
                    $status >= 300 && $status < 400 => ['redirect', 'Redirection', true],
                    in_array($status, [401, 403, 429], true) => ['restricted', $status === 429 ? 'Actif (limite de requêtes)' : 'Accès protégé', true],
                    in_array($status, [404, 410], true) => ['missing', 'Lien introuvable', false],
                    $status >= 500 => ['server_error', 'Erreur du serveur', false],
                    default => ['unreachable', 'Réponse HTTP '.$status, false],
                };
                $results[$key] = $this->result($link, $state, $label, $active);
            } catch (\Throwable) {
                $results[$key] = $this->result($link, 'unreachable', 'Inaccessible', false);
            }
        }

        ksort($results);
        return array_values($results);
    }

    /** @param array<string, mixed> $options */
    private function requestStatus(string $method, string $url, array $options): int
    {
        $response = $this->client->request($method, $url, $options);
        try {
            return $response->getStatusCode();
        } finally {
            $response->cancel();
        }
    }

    /** @return array{id:string,title:string,url:string,state:string,label:string,active:bool} */
    private function result(Link $link, string $state, string $label, bool $active): array
    {
        return ['id' => $link->getId(), 'title' => $link->getTitle(), 'url' => $link->getUrl(), 'state' => $state, 'label' => $label, 'active' => $active];
    }
}
