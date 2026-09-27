<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Model\Oauth;

use Magento\Framework\App\CacheInterface;

/**
 * UpturnStudio_Mcp
 *
 * Fixed-window failure counter for the OAuth endpoints, keyed independently by client_id
 * and by source IP - client_id alone isn't secret, since DCR hands them out freely.
 */
class Throttler
{
    private const CACHE_TAG = 'UPTURNSTUDIO_MCP_THROTTLE';
    private const MAX_ATTEMPTS = 10;
    private const WINDOW_SECONDS = 300;

    /**
     * @param CacheInterface $cache
     */
    public function __construct(
        private readonly CacheInterface $cache
    ) {
    }

    /**
     * @param string $bucket A short label for the endpoint, e.g. "token" or "register"
     * @param string $identifier client_id or IP address
     * @return bool True if this call is allowed to proceed
     */
    public function isAllowed(string $bucket, string $identifier): bool
    {
        return (int) $this->cache->load($this->cacheKey($bucket, $identifier)) < self::MAX_ATTEMPTS;
    }

    /**
     * @param string $bucket
     * @param string $identifier
     * @return void
     */
    public function recordFailure(string $bucket, string $identifier): void
    {
        $key = $this->cacheKey($bucket, $identifier);
        $count = (int) $this->cache->load($key);
        $this->cache->save((string) ($count + 1), $key, [self::CACHE_TAG], self::WINDOW_SECONDS);
    }

    /**
     * @param string $bucket
     * @param string $identifier
     * @return string
     */
    private function cacheKey(string $bucket, string $identifier): string
    {
        return 'upturnstudio_mcp_throttle_' . $bucket . '_' . hash('sha256', $identifier);
    }
}
