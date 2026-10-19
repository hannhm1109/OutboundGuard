<?php

declare(strict_types=1);

namespace Hanane\OutboundGuardBundle\Metadata;

/** Safe, serializable identity; never retains the source URL or query string. */
final class RequestTarget
{
    private function __construct(
        public readonly ?string $host,
        public readonly ?string $path,
        public readonly string $displayUrl,
        public readonly string $fingerprint,
    ) {
    }

    public static function fromUrl(string $method, string $url, string $key, bool $capturePaths = false): self
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '/';
        $path = '' === $path ? '/' : $path;

        // Failed requests can have invalid/relative URLs. Never display those verbatim.
        if (!in_array($scheme, ['http', 'https'], true) || '' === $host) {
            return new self(null, null, '[unknown target]', hash_hmac('sha256', $method.' [unknown target]', $key));
        }

        $port = $parts['port'] ?? null;
        $isDefaultPort = ('https' === $scheme && 443 === $port) || ('http' === $scheme && 80 === $port);
        $origin = $scheme.'://'.$host.(null !== $port && !$isDefaultPort ? ':'.$port : '');

        return new self(
            $host,
            $capturePaths ? $path : null,
            $origin.($capturePaths ? $path : '/[path hidden]'),
            hash_hmac('sha256', strtoupper($method).' '.$origin.$path, $key),
        );
    }
}
