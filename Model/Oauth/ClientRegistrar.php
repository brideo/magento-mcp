<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Model\Oauth;

use UpturnStudio\Mcp\Model\ResourceModel\OauthClientStorage;

/**
 * UpturnStudio_Mcp
 *
 * RFC 7591 Dynamic Client Registration. Always issues a public client (no secret) - this is
 * what Claude uses "by default" with zero extra admin configuration.
 */
class ClientRegistrar
{
    private const ALLOWED_GRANT_TYPES = ['authorization_code', 'refresh_token'];
    private const CLIENT_NAME_MAX_LENGTH = 255;

    /**
     * @param Crypto $crypto
     * @param RedirectUriValidator $redirectUriValidator
     * @param OauthClientStorage $clientStorage
     */
    public function __construct(
        private readonly Crypto $crypto,
        private readonly RedirectUriValidator $redirectUriValidator,
        private readonly OauthClientStorage $clientStorage
    ) {
    }

    /**
     * @param array $payload Decoded DCR request body
     * @return array RFC 7591 response body
     * @throws \InvalidArgumentException
     */
    public function register(array $payload): array
    {
        $rawRedirectUris = $payload['redirect_uris'] ?? null;
        if (!is_array($rawRedirectUris) || $rawRedirectUris === []) {
            throw new \InvalidArgumentException('redirect_uris is required and must be a non-empty array.');
        }

        $normalizedUris = [];
        foreach ($rawRedirectUris as $uri) {
            if (!is_string($uri)) {
                throw new \InvalidArgumentException('Each redirect_uri must be a string.');
            }
            $normalized = $this->redirectUriValidator->normalize($uri);
            if ($normalized === null) {
                throw new \InvalidArgumentException(sprintf('redirect_uri "%s" is not an acceptable redirect URI.', $uri));
            }
            $normalizedUris[] = $normalized;
        }
        $normalizedUris = array_values(array_unique($normalizedUris));

        $clientName = isset($payload['client_name']) && is_string($payload['client_name'])
            ? substr($payload['client_name'], 0, self::CLIENT_NAME_MAX_LENGTH)
            : null;

        $clientId = $this->crypto->randomToken();

        $this->clientStorage->save([
            'client_id' => $clientId,
            'client_name' => $clientName,
            'redirect_uris' => json_encode($normalizedUris),
            'token_endpoint_auth_method' => 'none',
            'grant_types' => json_encode(self::ALLOWED_GRANT_TYPES),
        ]);

        return [
            'client_id' => $clientId,
            'client_id_issued_at' => time(),
            'redirect_uris' => $normalizedUris,
            'grant_types' => self::ALLOWED_GRANT_TYPES,
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'client_name' => $clientName,
        ];
    }
}
