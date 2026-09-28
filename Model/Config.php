<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * UpturnStudio_Mcp
 */
class Config
{
    private const XML_PATH_ENABLED = 'upturnstudio_mcp/general/enabled';
    private const XML_PATH_PUBLIC_BASE_URL = 'upturnstudio_mcp/general/public_base_url';
    private const XML_PATH_ACCESS_TOKEN_TTL = 'upturnstudio_mcp/token/access_token_ttl';
    private const XML_PATH_REFRESH_TOKEN_TTL = 'upturnstudio_mcp/token/refresh_token_ttl';
    private const XML_PATH_AUTH_CODE_TTL = 'upturnstudio_mcp/token/auth_code_ttl';
    private const XML_PATH_PAGESPEED_API_KEY = 'upturnstudio_mcp/core_web_vitals/pagespeed_api_key';

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue(self::XML_PATH_ENABLED);
    }

    /**
     * The canonical public HTTPS URL for this connector, used as the OAuth issuer and as the
     * "resource" value in protected-resource metadata. Deliberately not derived from the current
     * request's URI (base-URL resolution can drift), and deliberately not silently defaulted -
     * Claude validates the issuer it receives against what it read at discovery time, so any
     * drift here becomes an opaque connection failure on the client side.
     *
     * @return string
     * @throws LocalizedException
     */
    public function getPublicBaseUrl(): string
    {
        $value = (string) $this->scopeConfig->getValue(self::XML_PATH_PUBLIC_BASE_URL);
        $value = rtrim($value, '/');
        if ($value === '' || !str_starts_with($value, 'https://')) {
            throw new LocalizedException(
                __('The MCP connector public base URL is not configured. Set Stores > Configuration > '
                    . 'Advanced > UpturnStudio Mcp to a public https:// URL before using the connector.')
            );
        }
        return $value;
    }

    /**
     * @return string
     * @throws LocalizedException
     */
    public function getMcpEndpointUrl(): string
    {
        return $this->getPublicBaseUrl() . '/mcp';
    }

    /**
     * @return int
     */
    public function getAccessTokenTtl(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_PATH_ACCESS_TOKEN_TTL);
    }

    /**
     * @return int
     */
    public function getRefreshTokenTtl(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_PATH_REFRESH_TOKEN_TTL);
    }

    /**
     * @return int
     */
    public function getAuthCodeTtl(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_PATH_AUTH_CODE_TTL);
    }

    /**
     * Optional - the PageSpeed Insights API works unauthenticated at a lower rate limit, so
     * null (not configured) is a normal, supported state, not an error.
     *
     * @return string|null
     */
    public function getPageSpeedApiKey(): ?string
    {
        $value = (string) $this->scopeConfig->getValue(self::XML_PATH_PAGESPEED_API_KEY);
        return $value !== '' ? $value : null;
    }
}
