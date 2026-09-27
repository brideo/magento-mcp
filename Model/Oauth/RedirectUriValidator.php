<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Model\Oauth;

/**
 * UpturnStudio_Mcp
 *
 * Validates and normalizes OAuth redirect_uri values. Default rule is byte-for-byte exact
 * match; the only relaxation is a port-agnostic match for loopback (RFC 8252) redirect URIs,
 * since Claude Code uses a different ephemeral port per session.
 */
class RedirectUriValidator
{
    public const CLAUDE_HOSTED_CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

    private const LOOPBACK_HOSTS = ['127.0.0.1', 'localhost'];

    /**
     * Normalize a redirect_uri to its canonical stored form, or null if it is not an
     * acceptable redirect_uri at all (wrong scheme, contains a fragment/traversal/userinfo,
     * or isn't parseable).
     *
     * @param string $redirectUri
     * @return string|null
     */
    public function normalize(string $redirectUri): ?string
    {
        if ($redirectUri === self::CLAUDE_HOSTED_CALLBACK) {
            return self::CLAUDE_HOSTED_CALLBACK;
        }

        if (str_contains($redirectUri, '#')) {
            return null;
        }

        $parts = parse_url($redirectUri);
        if ($parts === false || !isset($parts['scheme'], $parts['host'], $parts['path']) || isset($parts['user'])) {
            return null;
        }

        $path = $parts['path'];
        if (str_contains($path, '//') || str_contains(rawurldecode($path), '..')) {
            return null;
        }

        $host = strtolower($parts['host']);
        $isLoopback = in_array($host, self::LOOPBACK_HOSTS, true);

        if ($isLoopback) {
            if ($parts['scheme'] !== 'http') {
                return null;
            }
            // Port intentionally dropped - loopback redirect URIs are matched port-agnostically.
            return $this->rebuild('http', $host, null, $path, $parts['query'] ?? null);
        }

        if ($parts['scheme'] !== 'https') {
            return null;
        }

        return $this->rebuild('https', $host, $parts['port'] ?? null, $path, $parts['query'] ?? null);
    }

    /**
     * Whether an incoming redirect_uri matches one of a client's already-normalized,
     * registered redirect URIs.
     *
     * @param string $incomingRedirectUri
     * @param string[] $registeredNormalizedUris
     * @return bool
     */
    public function matches(string $incomingRedirectUri, array $registeredNormalizedUris): bool
    {
        $normalized = $this->normalize($incomingRedirectUri);
        return $normalized !== null && in_array($normalized, $registeredNormalizedUris, true);
    }

    /**
     * @param string $scheme
     * @param string $host
     * @param int|null $port
     * @param string $path
     * @param string|null $query
     * @return string
     */
    private function rebuild(string $scheme, string $host, ?int $port, string $path, ?string $query): string
    {
        $uri = $scheme . '://' . $host;
        if ($port !== null) {
            $uri .= ':' . $port;
        }
        $uri .= $path;
        if ($query !== null && $query !== '') {
            $uri .= '?' . $query;
        }
        return $uri;
    }
}
