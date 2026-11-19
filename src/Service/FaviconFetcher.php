<?php

declare(strict_types=1);

namespace Neox\BoardBundle\Service;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Neox\BoardBundle\Entity\Link;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Retrieves and stores site icons advertised in HTML or HTTP Link headers. */
final class FaviconFetcher
{
    private HttpClientInterface $safeClient;

    /** @var array<string, string|null> */
    private array $privateAddressCache = [];

    public function __construct(private readonly HttpClientInterface $client)
    {
        $this->safeClient = new NoPrivateNetworkHttpClient($client);
    }

    public function fetchFor(Link $link): void
    {
        if ($link->getFaviconData() !== null) {
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
        $pagePrivateIp = $this->privateAddressForHost((string) $pageParts['host']);
        $pageClient = $this->clientFor($pageParts, $pageParts, $pagePrivateIp);
        $pageIsLan = $pagePrivateIp !== null;
        $page = $this->download($pageClient, $pageUrl, 1_048_576, 3, false, $pageIsLan ? (string) $pageParts['host'] : null);
        $discoveryUrl = $pageUrl;
        // A deep link may return 404 or require authentication while the site's
        // root still advertises its favicon. Reuse the pinned internal client for
        // this same-origin fallback instead of giving up after the first request.
        if ($page === null && (($pageParts['path'] ?? '/') !== '/')) {
            $discoveryUrl = $this->originUrl($pageParts, '/');
            $page = $this->download($pageClient, $discoveryUrl, 1_048_576, 3, false, $pageIsLan ? (string) $pageParts['host'] : null);
        }
        $candidates = [];
        if ($page !== null) {
            $candidates = [...$this->candidatesFromLinkHeaders($page['link_headers'], $discoveryUrl), ...$this->candidatesFromHtml($page['body'], $discoveryUrl)];
        }
        // Some internal HTTP aliases expose only a lightweight redirect/landing
        // response while the real site (and its favicon) is served on HTTPS.
        if ($candidates === [] && $pageIsLan && strtolower((string) ($pageParts['scheme'] ?? '')) === 'http' && filter_var((string) ($pageParts['host'] ?? ''), FILTER_VALIDATE_IP) === false) {
            $secureParts = $pageParts;
            $secureParts['scheme'] = 'https';
            $secureUrl = $this->buildUrl($secureParts);
            $securePage = $this->download($pageClient, $secureUrl, 1_048_576, 3, false, (string) $pageParts['host']);
            if ($securePage !== null) {
                $candidates = [...$this->candidatesFromLinkHeaders($securePage['link_headers'], $secureUrl), ...$this->candidatesFromHtml($securePage['body'], $secureUrl)];
            }
        }
        $candidates[] = $this->originUrl($pageParts, '/favicon.ico');

        foreach (array_unique($candidates) as $candidate) {
            $parts = parse_url($candidate);
            if (!is_array($parts) || !isset($parts['host'])) {
                continue;
            }

            $iconClient = $this->clientFor($pageParts, $parts, $pagePrivateIp);
            $sameOriginLan = $pageIsLan && $this->sameInternalHost($pageParts, $parts);
            $icon = $this->download($iconClient, $candidate, 262_144, 2, true, $sameOriginLan ? (string) $pageParts['host'] : null);
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

            $thumbnail = $this->thumbnail($body, $extension);
            if ($thumbnail !== null) $link->setFaviconData(base64_encode($thumbnail));
            if ($thumbnail !== null) return;
        }
    }

    private function thumbnail(string $body, string $extension): ?string
    {
        if ($extension === 'svg') {
            $body = preg_replace_callback('/<svg\b[^>]*>/i', static function (array $match): string {
                $tag = preg_replace('/\bwidth\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', 'width="25"', $match[0], 1, $widthCount) ?? $match[0];
                if ($widthCount === 0) $tag = preg_replace('/<svg\b/i', '<svg width="25"', $tag, 1) ?? $tag;
                $tag = preg_replace('/\bheight\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', 'height="25"', $tag, 1, $heightCount) ?? $tag;
                if ($heightCount === 0) $tag = preg_replace('/<svg\b/i', '<svg height="25"', $tag, 1) ?? $tag;
                return $tag;
            }, $body, 1) ?? $body;
            return $body;
        }
        if ($extension === 'ico') {
            $body = $this->extractIcoImage($body);
            if ($body === null) return null;
        }
        if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) return null;
        $size = @getimagesizefromstring($body);
        if (!is_array($size) || ($size[0] * $size[1]) > 16_000_000) return null;
        $source = @imagecreatefromstring($body);
        if ($source === false) return null;
        $thumbnail = imagecreatetruecolor(25, 25);
        imagealphablending($thumbnail, false);
        imagesavealpha($thumbnail, true);
        $transparent = imagecolorallocatealpha($thumbnail, 0, 0, 0, 127);
        imagefilledrectangle($thumbnail, 0, 0, 24, 24, $transparent);
        $width = imagesx($source); $height = imagesy($source);
        $scale = min(25 / max(1, $width), 25 / max(1, $height));
        $targetWidth = max(1, (int) round($width * $scale)); $targetHeight = max(1, (int) round($height * $scale));
        imagecopyresampled($thumbnail, $source, intdiv(25 - $targetWidth, 2), intdiv(25 - $targetHeight, 2), 0, 0, $targetWidth, $targetHeight, $width, $height);
        ob_start(); $ok = imagewebp($thumbnail, null, 82); $result = ob_get_clean();
        imagedestroy($source); imagedestroy($thumbnail);
        return $ok && is_string($result) ? $result : null;
    }

    private function extractIcoImage(string $ico): ?string
    {
        if (strlen($ico) < 22 || substr($ico, 0, 4) !== "\0\0\x01\0") return null;
        $count = unpack('v', substr($ico, 4, 2))[1] ?? 0;
        $pngImage = null;
        $dibImage = null;
        for ($index = 0; $index < min($count, 64); ++$index) {
            $entry = substr($ico, 6 + ($index * 16), 16);
            if (strlen($entry) !== 16) break;
            $values = unpack('Cwidth/Cheight/Ccolors/Creserved/vplanes/vbits/Vlength/Voffset', $entry);
            $length = $values['length']; $offset = $values['offset'];
            if ($length < 8 || $offset < 22 || $offset + $length > strlen($ico)) continue;
            $image = substr($ico, $offset, $length);
            if (str_starts_with($image, "\x89PNG\r\n\x1a\n")) {
                $pngImage ??= $image;
                continue;
            }
            if (strlen($image) < 40) continue;
            $headerSize = unpack('V', substr($image, 0, 4))[1] ?? 0;
            $storedHeight = unpack('V', substr($image, 8, 4))[1] ?? 0;
            if ($headerSize < 40 || $headerSize > strlen($image) || $storedHeight < 2) continue;
            $dib = substr_replace($image, pack('V', intdiv($storedHeight, 2)), 8, 4);
            $bits = unpack('v', substr($dib, 14, 2))[1] ?? 32;
            $colors = unpack('V', substr($dib, 32, 4))[1] ?? 0;
            $paletteColors = $colors ?: ($bits <= 8 ? 1 << $bits : 0);
            $pixelOffset = 14 + $headerSize + ($paletteColors * 4);
            $bmp = pack('a2VvvV', 'BM', 14 + strlen($dib), 0, 0, $pixelOffset).$dib;
            $dibImage ??= $bmp;
        }

        return $pngImage ?? $dibImage;
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
    private function clientFor(array $pageParts, array $iconParts, ?string $resolvedPrivateIp = null): HttpClientInterface
    {
        $host = (string) ($pageParts['host'] ?? '');
        if ($this->sameInternalHost($pageParts, $iconParts) && $resolvedPrivateIp !== null) {
            // Pin the internal hostname to its resolved LAN address. Callers also
            // restrict redirects to the same hostname, so it cannot pivot to another host.
            $options = ['resolve' => [$host => $resolvedPrivateIp]];
            if ($this->isWipHost($host)) {
                // Local development certificates are issued by the host's local CA,
                // which is not installed in the worker container.
                $options['verify_peer'] = false;
            }

            return $this->client->withOptions($options);
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

    /** @param array<string, mixed> $first @param array<string, mixed> $second */
    private function sameInternalHost(array $first, array $second): bool
    {
        return strtolower(trim((string) ($first['host'] ?? ''), '[]')) === strtolower(trim((string) ($second['host'] ?? ''), '[]'));
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

    private function privateAddressForHost(string $host): ?string
    {
        $cacheKey = strtolower(trim($host, '[]'));
        if (array_key_exists($cacheKey, $this->privateAddressCache)) {
            return $this->privateAddressCache[$cacheKey];
        }

        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return $this->privateAddressCache[$cacheKey] = $this->isLanAddress($literal) ? $literal : null;
        }

        // Internal DNS entries in Docker can briefly return an empty result while
        // the resolver refreshes a zero-TTL record. Retry before falling back to the
        // public SSRF-safe client, otherwise an internal favicon is missed randomly.
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
            foreach ($records as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($ip) && $this->isLanAddress($ip)) {
                    return $this->privateAddressCache[$cacheKey] = $ip;
                }
            }
            $fallback = gethostbyname($host);
            if ($fallback !== $host && $this->isLanAddress($fallback)) {
                return $this->privateAddressCache[$cacheKey] = $fallback;
            }
            if ($attempt < 2) usleep(10_000);
        }

        // In Docker, local *.wip names can resolve to 127.0.0.1 on the host.
        // That address points back into the worker container, so route those names
        // through the shared reverse proxy when one is configured.
        if ($this->isWipHost($host) && ($proxyHost = getenv('NEOX_BOARD_INTERNAL_PROXY_HOST')) !== false && $proxyHost !== '') {
            $proxyIp = filter_var($proxyHost, FILTER_VALIDATE_IP) !== false ? $proxyHost : gethostbyname($proxyHost);
            if ($proxyIp !== $proxyHost || filter_var($proxyHost, FILTER_VALIDATE_IP) !== false) {
                return $this->privateAddressCache[$cacheKey] = $this->isLanAddress($proxyIp) ? $proxyIp : null;
            }
        }

        return $this->privateAddressCache[$cacheKey] = null;
    }

    private function isWipHost(string $host): bool
    {
        return str_ends_with(strtolower(rtrim($host, '.')), '.wip');
    }

    /** @return array{body: string, link_headers: list<string>}|null */
    private function download(HttpClientInterface $client, string $url, int $maximumBytes, int $maximumRedirects, bool $icon = false, ?string $pinnedHost = null): ?array
    {
        try {
            $redirects = 0;
            while (true) {
                $response = $client->request('GET', $url, [
                    // Private hosts are followed manually so a redirect cannot escape
                    // the resolved host/IP pair. Public hosts retain normal redirects.
                    'max_redirects' => $pinnedHost === null ? $maximumRedirects : 0,
                    'timeout' => 2.0,
                    'max_duration' => 3.0,
                    'headers' => ['Accept' => $icon ? 'image/*,application/octet-stream;q=0.8' : 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.2'],
                ]);
                $status = $response->getStatusCode();
                if ($pinnedHost !== null && in_array($status, [301, 302, 303, 307, 308], true) && $redirects < $maximumRedirects) {
                    $location = $response->getHeaders(false)['location'][0] ?? null;
                    $nextUrl = is_string($location) ? $this->resolveUrl($location, $url) : null;
                    $nextParts = $nextUrl !== null ? parse_url($nextUrl) : false;
                    if (!is_array($nextParts) || strtolower((string) ($nextParts['host'] ?? '')) !== strtolower($pinnedHost)) {
                        $response->cancel();
                        return null;
                    }
                    $response->cancel();
                    $url = $nextUrl;
                    ++$redirects;
                    continue;
                }
                if ($status !== 200) {
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
            }
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
