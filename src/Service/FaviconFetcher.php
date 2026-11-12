<?php

declare(strict_types=1);

namespace Neox\BoardBundle\Service;

use Neox\BoardBundle\Entity\Link;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Retrieves conventional site favicons, including explicit LAN addresses. */
final class FaviconFetcher
{
    private HttpClientInterface $safeClient;

    public function __construct(private readonly KernelInterface $kernel, private readonly HttpClientInterface $client)
    {
        $this->safeClient = new NoPrivateNetworkHttpClient($client);
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
        // Only a literal LAN IP may bypass the private-network guard. Redirects stay disabled.
        $requestClient = $this->isLanAddress((string) $parts['host']) ? $this->client : $this->safeClient;

        try {
            $response = $requestClient->request('GET', $url, [
                'max_redirects' => 0,
                'timeout' => 2.0,
                'max_duration' => 3.0,
                'headers' => ['Accept' => 'image/x-icon,image/vnd.microsoft.icon,image/png,image/*;q=0.8'],
            ]);
            if ($response->getStatusCode() !== 200) {
                $response->cancel();
                return;
            }

            $body = '';
            foreach ($requestClient->stream($response, 0.25) as $chunk) {
                if ($chunk->isTimeout()) {
                    continue;
                }
                $body .= $chunk->getContent();
                if (strlen($body) > 262144) {
                    $response->cancel();
                    return;
                }
            }
            $extension = $this->detectImageExtension($body);
            if ($extension === null) {
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

    private function isLanAddress(string $host): bool
    {
        $address = @inet_pton(trim($host, '[]'));
        if ($address === false) return false;
        if (strlen($address) === 16) return (ord($address[0]) & 0xfe) === 0xfc;
        $first = ord($address[0]);
        $second = ord($address[1]);
        return $first === 10 || ($first === 172 && $second >= 16 && $second <= 31) || ($first === 192 && $second === 168);
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

    private function detectImageExtension(string $body): ?string
    {
        foreach (['ico', 'png', 'jpg', 'gif', 'webp'] as $extension) {
            if ($this->looksLikeImage($body, $extension)) return $extension;
        }
        return null;
    }
}
