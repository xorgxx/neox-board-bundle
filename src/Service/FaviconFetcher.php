<?php

declare(strict_types=1);

namespace Neox\BoardBundle\Service;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Neox\BoardBundle\Entity\Link;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Retrieves and stores site icons advertised in HTML or HTTP Link headers. */
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

        $pageUrl = $this->httpUrl($link->getUrl());
        if ($pageUrl === null) {
            return;
        }

        $pageParts = parse_url($pageUrl);
        if (!is_array($pageParts)) {
            return;
        }
        $pageClient = $this->clientFor($pageParts, $pageParts);
        $page = $this->download($pageClient, $pageUrl, 1_048_576, $this->isLanAddress((string) $pageParts['host']) ? 0 : 3);
        $candidates = [];
        if ($page !== null) {
            $candidates = [...$this->candidatesFromLinkHeaders($page['link_headers'], $pageUrl), ...$this->candidatesFromHtml($page['body'], $pageUrl)];
        }
        $candidates[] = $this->originUrl($pageParts, '/favicon.ico');

        foreach (array_unique($candidates) as $candidate) {
            $parts = parse_url($candidate);
            if (!is_array($parts) || !isset($parts['host'])) {
                continue;
            }

            $iconClient = $this->clientFor($pageParts, $parts);
            $sameOriginLan = $this->isLanAddress((string) $pageParts['host']) && $this->sameOrigin($pageParts, $parts);
            $icon = $this->download($iconClient, $candidate, 262_144, $sameOriginLan ? 0 : 2, true);
            if ($icon === null) {
                continue;
            }

            $body = $icon['body'];
            $extension = $this->detectImageExtension($body);
            if ($extension === null) {
                continue;
            }
            if ($extension === 'svg') {
                $body = $this->sanitizeSvg($body);
                if ($body === null) {
                    continue;
                }
            }

            $directory = $this->kernel->getProjectDir().'/var/neox-board/favicons';
            if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
                return;
            }
            $filename = hash('sha256', $candidate).'.'.$extension;
            if (@file_put_contents($directory.'/'.$filename, $body, LOCK_EX) !== false) {
                $link->setFaviconFilename($filename);
                return;
            }
        }
    }

    /** @param array<string, mixed> $parts */
    private function originUrl(array $parts, string $path): string
    {
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        return $scheme.'://'.$parts['host'].$port.$path;
    }

    private function httpUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) {
            return null;
        }

        unset($parts['fragment']);
        return $this->buildUrl($parts);
    }

    /** @param array<string, mixed> $parts */
    private function buildUrl(array $parts): string
    {
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $user = isset($parts['user']) ? rawurlencode((string) $parts['user']).(isset($parts['pass']) ? ':'.rawurlencode((string) $parts['pass']) : '').'@' : '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        return $scheme.'://'.$user.$parts['host'].$port.($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /** @param array<string, mixed> $pageParts @param array<string, mixed> $iconParts */
    private function clientFor(array $pageParts, array $iconParts): HttpClientInterface
    {
        if ($this->isLanAddress((string) ($pageParts['host'] ?? '')) && $this->sameOrigin($pageParts, $iconParts)) {
            return $this->client;
        }

        return $this->safeClient;
    }

    /** @param array<string, mixed> $first @param array<string, mixed> $second */
    private function sameOrigin(array $first, array $second): bool
    {
        return strtolower((string) ($first['scheme'] ?? '')) === strtolower((string) ($second['scheme'] ?? ''))
            && strtolower(trim((string) ($first['host'] ?? ''), '[]')) === strtolower(trim((string) ($second['host'] ?? ''), '[]'))
            && (int) ($first['port'] ?? (strtolower((string) ($first['scheme'] ?? '')) === 'https' ? 443 : 80))
                === (int) ($second['port'] ?? (strtolower((string) ($second['scheme'] ?? '')) === 'https' ? 443 : 80));
    }

    private function isLanAddress(string $host): bool
    {
        $address = @inet_pton(trim($host, '[]'));
        if ($address === false) {
            return false;
        }
        if (strlen($address) === 16) {
            return (ord($address[0]) & 0xfe) === 0xfc;
        }

        $first = ord($address[0]);
        $second = ord($address[1]);
        return $first === 10 || ($first === 172 && $second >= 16 && $second <= 31) || ($first === 192 && $second === 168);
    }

    /** @return array{body: string, link_headers: list<string>}|null */
    private function download(HttpClientInterface $client, string $url, int $maximumBytes, int $maximumRedirects, bool $icon = false): ?array
    {
        try {
            $response = $client->request('GET', $url, [
                'max_redirects' => $maximumRedirects,
                'timeout' => 2.0,
                'max_duration' => 3.0,
                'headers' => ['Accept' => $icon ? 'image/*,application/octet-stream;q=0.8' : 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.2'],
            ]);
            if ($response->getStatusCode() !== 200) {
                $response->cancel();
                return null;
            }

            $body = '';
            foreach ($client->stream($response, 0.25) as $chunk) {
                if ($chunk->isTimeout()) {
                    continue;
                }
                $body .= $chunk->getContent();
                if (strlen($body) > $maximumBytes) {
                    $response->cancel();
                    return null;
                }
            }

            $headers = $response->getHeaders(false);
            return ['body' => $body, 'link_headers' => array_values($headers['link'] ?? [])];
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param list<string> $headers @return list<string> */
    private function candidatesFromLinkHeaders(array $headers, string $baseUrl): array
    {
        $candidates = [];
        foreach ($headers as $header) {
            if (preg_match_all('/<([^>]+)>\s*;\s*([^,]+)/', $header, $matches, PREG_SET_ORDER) === false) {
                continue;
            }
            foreach ($matches as $match) {
                if (preg_match('/(?:^|;)\s*rel\s*=\s*"?[^";]*(?:icon|apple-touch-icon|mask-icon)/i', ';'.$match[2]) !== 1) {
                    continue;
                }
                $url = $this->resolveUrl($match[1], $baseUrl);
                if ($url !== null) {
                    $candidates[] = $url;
                }
            }
        }

        return $candidates;
    }

    /** @return list<string> */
    private function candidatesFromHtml(string $html, string $baseUrl): array
    {
        if (stripos($html, '<head') === false) {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            if (!$document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return [];
            }

            $xpath = new DOMXPath($document);
            $nodes = $xpath->query('//head/link[@href and @rel]');
            if ($nodes === false) {
                return [];
            }

            $candidates = [];
            foreach ($nodes as $node) {
                if (!$node instanceof DOMElement) {
                    continue;
                }
                $relations = preg_split('/\s+/', strtolower(trim($node->getAttribute('rel')))) ?: [];
                if (array_intersect($relations, ['icon', 'apple-touch-icon', 'apple-touch-icon-precomposed', 'mask-icon', 'fluid-icon']) === []) {
                    continue;
                }
                $url = $this->resolveUrl(trim($node->getAttribute('href')), $baseUrl);
                if ($url !== null) {
                    $candidates[] = $url;
                }
            }

            return $candidates;
        } catch (\Throwable) {
            return [];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function resolveUrl(string $reference, string $baseUrl): ?string
    {
        if ($reference === '' || preg_match('/^(?:data|javascript|blob):/i', $reference) === 1) {
            return null;
        }

        $base = parse_url($baseUrl);
        if (!is_array($base)) {
            return null;
        }
        $referenceParts = parse_url($reference);
        if ($referenceParts === false) {
            return null;
        }
        if (isset($referenceParts['scheme'])) {
            return in_array(strtolower((string) $referenceParts['scheme']), ['http', 'https'], true) ? $this->httpUrl($reference) : null;
        }

        if (str_starts_with($reference, '//')) {
            return $this->httpUrl((string) $base['scheme'].':'.$reference);
        }

        if (isset($referenceParts['host'])) {
            $referenceParts['scheme'] = $base['scheme'];
            return $this->httpUrl($this->buildUrl($referenceParts));
        }

        if (str_starts_with($reference, '#')) {
            return null;
        }

        $path = (string) ($referenceParts['path'] ?? '');
        if ($path === '') {
            $path = (string) ($base['path'] ?? '/');
        } elseif (!str_starts_with($path, '/')) {
            $basePath = (string) ($base['path'] ?? '/');
            $path = substr($basePath, 0, (int) strrpos($basePath, '/') + 1).$path;
        }
        $path = $this->normalizePath($path);
        $resolved = ['scheme' => $base['scheme'], 'host' => $base['host'], 'path' => $path];
        if (isset($base['port'])) {
            $resolved['port'] = $base['port'];
        }
        if (isset($referenceParts['query'])) {
            $resolved['query'] = $referenceParts['query'];
        } elseif (!isset($referenceParts['path']) && isset($base['query'])) {
            $resolved['query'] = $base['query'];
        }

        return $this->buildUrl($resolved);
    }

    private function normalizePath(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return '/'.implode('/', $segments).(str_ends_with($path, '/') && $segments !== [] ? '/' : '');
    }

    private function looksLikeSvg(string $body): bool
    {
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;
        return preg_match('/^(?:<\?xml\b[^>]*>\s*)?<svg\b/i', ltrim($body)) === 1;
    }

    private function sanitizeSvg(string $body): ?string
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            $document->resolveExternals = false;
            $document->substituteEntities = false;
            if (!$document->loadXML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING) || strtolower($document->documentElement?->localName ?? '') !== 'svg') {
                return null;
            }

            $xpath = new DOMXPath($document);
            foreach ($xpath->query('//*[local-name()="script" or local-name()="foreignObject" or local-name()="iframe" or local-name()="object" or local-name()="embed"]') ?: [] as $node) {
                $node->parentNode?->removeChild($node);
            }
            foreach ($xpath->query('//@*') ?: [] as $attribute) {
                $name = strtolower($attribute->nodeName);
                $value = trim($attribute->nodeValue ?? '');
                if (str_starts_with($name, 'on') || (($name === 'href' || str_ends_with($name, ':href')) && $value !== '' && !str_starts_with($value, '#')) || preg_match('/url\s*\(\s*["\']?(?:https?:|data:|javascript:|\/\/)/i', $value) === 1) {
                    $attribute->ownerElement?->removeAttributeNode($attribute);
                }
            }

            return $document->saveXML($document->documentElement) ?: null;
        } catch (\Throwable) {
            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public function getPath(string $filename): ?string
    {
        if (preg_match('/\A[a-f0-9]{64}\.(?:ico|png|jpg|gif|webp|avif|bmp|svg)\z/', $filename) !== 1) {
            return null;
        }
        $path = $this->kernel->getProjectDir().'/var/neox-board/favicons/'.$filename;
        return is_file($path) ? $path : null;
    }

    private function detectImageExtension(string $body): ?string
    {
        if (str_starts_with($body, "\x00\x00\x01\x00") || str_starts_with($body, "\x00\x00\x02\x00")) return 'ico';
        if (str_starts_with($body, "\x89PNG\r\n\x1a\n")) return 'png';
        if (str_starts_with($body, "\xff\xd8\xff")) return 'jpg';
        if (str_starts_with($body, 'GIF87a') || str_starts_with($body, 'GIF89a')) return 'gif';
        if (str_starts_with($body, 'RIFF') && substr($body, 8, 4) === 'WEBP') return 'webp';
        if (strlen($body) >= 12 && substr($body, 4, 4) === 'ftyp' && in_array(substr($body, 8, 4), ['avif', 'avis'], true)) return 'avif';
        if (str_starts_with($body, 'BM')) return 'bmp';
        if ($this->looksLikeSvg($body)) return 'svg';
        return null;
    }
}
