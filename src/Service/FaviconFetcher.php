<?php

declare(strict_types=1);

namespace Neox\BoardBundle\Service;

use Neox\BoardBundle\Entity\Link;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Retrieves conventional site favicons without allowing requests to private networks. */
final class FaviconFetcher
{
    private HttpClientInterface $client;

    public function __construct(private readonly KernelInterface $kernel, HttpClientInterface $client)
    {
        $this->client = new NoPrivateNetworkHttpClient($client);
    }

    public function fetchFor(Link $link): void
    {
        if ($link->getFaviconFilename() !== null && $this->getPath($link->getFaviconFilename()) !== null) {
            return;
        }

        $parts = parse_url($link->getUrl());
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) {
            return;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $url = strtolower((string) $parts['scheme']).'://'.$parts['host'].$port.'/favicon.ico';

        try {
            $response = $this->client->request('GET', $url, [
                'max_redirects' => 0,
                'timeout' => 2.0,
                'max_duration' => 3.0,
                'headers' => ['Accept' => 'image/x-icon,image/vnd.microsoft.icon,image/png,image/*;q=0.8'],
            ]);
            if ($response->getStatusCode() !== 200) {
                $response->cancel();
                return;
            }

            $mime = strtolower(trim(explode(';', $response->getHeaders(false)['content-type'][0] ?? '')[0]));
            $extension = match ($mime) {
                'image/x-icon', 'image/vnd.microsoft.icon' => 'ico',
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                default => null,
            };
            if ($extension === null) {
                $response->cancel();
                return;
            }

            $body = '';
            foreach ($this->client->stream($response, 0.25) as $chunk) {
                if ($chunk->isTimeout()) {
                    continue;
                }
                $body .= $chunk->getContent();
                if (strlen($body) > 262144) {
                    $response->cancel();
                    return;
                }
            }
            if ($body === '' || !$this->looksLikeImage($body, $extension)) {
                return;
            }

            $directory = $this->kernel->getProjectDir().'/var/neox-board/favicons';
            if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
                return;
            }
            $filename = hash('sha256', $url).'.'.$extension;
            if (@file_put_contents($directory.'/'.$filename, $body, LOCK_EX) !== false) {
                $link->setFaviconFilename($filename);
            }
        } catch (\Throwable) {
            // A favicon is an optional enhancement; inaccessible or unsafe hosts
            // must never prevent a user from saving an otherwise valid link.
        }
    }

    public function getPath(string $filename): ?string
    {
        if (preg_match('/\A[a-f0-9]{64}\.(?:ico|png|jpg|gif|webp)\z/', $filename) !== 1) {
            return null;
        }
        $path = $this->kernel->getProjectDir().'/var/neox-board/favicons/'.$filename;
        return is_file($path) ? $path : null;
    }

    private function looksLikeImage(string $body, string $extension): bool
    {
        return match ($extension) {
            'ico' => str_starts_with($body, "\x00\x00\x01\x00") || str_starts_with($body, "\x00\x00\x02\x00"),
            'png' => str_starts_with($body, "\x89PNG\r\n\x1a\n"),
            'jpg' => str_starts_with($body, "\xff\xd8\xff"),
            'gif' => str_starts_with($body, 'GIF87a') || str_starts_with($body, 'GIF89a'),
            'webp' => str_starts_with($body, 'RIFF') && substr($body, 8, 4) === 'WEBP',
        };
    }
}
