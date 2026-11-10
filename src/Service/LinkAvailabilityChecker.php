<?php

declare(strict_types=1);

namespace Neox\BoardBundle\Service;

use Neox\BoardBundle\Entity\Link;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Checks every saved HTTP link, including local development hosts. */
final class LinkAvailabilityChecker
{
    private HttpClientInterface $client;

    public function __construct(HttpClientInterface $client)
    {
        $this->client = $client;
    }

    /** @param list<Link> $links @return list<array{title:string,url:string,state:string,label:string,active:bool}> */
    public function check(array $links): array
    {
        $results = [];
        foreach (array_chunk($links, 20, true) as $batch) {
            $responses = [];
            $metadata = [];
            foreach ($batch as $key => $link) {
                $url = $link->getUrl();
                $parts = parse_url($url);
                if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) {
                    $results[$key] = $this->result($link, 'unsupported', 'Adresse non vérifiable', false);
                    continue;
                }
                try {
                    $options = [
                        'max_redirects' => 0,
                        'timeout' => 2.0,
                        'max_duration' => 3.0,
                        'headers' => ['Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8'],
                    ];
                    $host = strtolower((string) $parts['host']);
                    $hostGateway = gethostbyname('host.docker.internal');
                    if (str_ends_with($host, '.wip') && $hostGateway !== 'host.docker.internal' && filter_var($hostGateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $options['resolve'][$host] = $hostGateway;
                        $options['verify_peer'] = false;
                    }
                    $response = $this->client->request('HEAD', $url, [
                        ...$options,
                    ]);
                    $id = spl_object_id($response);
                    $responses[$id] = $response;
                    $metadata[$id] = [$key, $link];
                } catch (\Throwable) {
                    $results[$key] = $this->result($link, 'unreachable', 'Inaccessible', false);
                }
            }

            if ($responses === []) continue;
            foreach ($metadata as $id => [$key, $link]) {
                try {
                    $status = $responses[$id]->getStatusCode();
                    [$state, $label, $active] = match (true) {
                        $status >= 200 && $status < 300 => ['active', 'Actif', true],
                        $status >= 300 && $status < 400 => ['redirect', 'Redirection', true],
                        in_array($status, [401, 403], true) => ['restricted', 'Accès protégé', true],
                        in_array($status, [405, 501], true) => ['method', 'Site actif (HEAD refusé)', true],
                        in_array($status, [404, 410], true) => ['missing', 'Lien introuvable', false],
                        $status >= 500 => ['server_error', 'Erreur du serveur', false],
                        default => ['unreachable', 'Réponse HTTP '.$status, false],
                    };
                    $results[$key] = $this->result($link, $state, $label, $active);
                } catch (\Throwable) {
                    $results[$key] = $this->result($link, 'unreachable', 'Inaccessible', false);
                }
            }
        }

        ksort($results);
        return array_values($results);
    }

    private function result(Link $link, string $state, string $label, bool $active): array
    {
        return ['id' => $link->getId(), 'title' => $link->getTitle(), 'url' => $link->getUrl(), 'state' => $state, 'label' => $label, 'active' => $active];
    }
}
