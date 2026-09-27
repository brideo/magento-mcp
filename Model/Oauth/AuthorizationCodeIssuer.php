<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Model\Oauth;

use UpturnStudio\Mcp\Model\Config;
use UpturnStudio\Mcp\Model\ResourceModel\AccessTokenStorage;
use UpturnStudio\Mcp\Model\ResourceModel\AuthorizationCodeStorage;

/**
 * UpturnStudio_Mcp
 */
class AuthorizationCodeIssuer
{
    /**
     * @param Crypto $crypto
     * @param Config $config
     * @param AuthorizationCodeStorage $codeStorage
     * @param AccessTokenStorage $accessTokenStorage
     */
    public function __construct(
        private readonly Crypto $crypto,
        private readonly Config $config,
        private readonly AuthorizationCodeStorage $codeStorage,
        private readonly AccessTokenStorage $accessTokenStorage
    ) {
    }

    /**
     * @param string $clientId
     * @param string $redirectUri
     * @param string $codeChallenge
     * @param string $codeChallengeMethod
     * @param string|null $resource
     * @param string|null $scope
     * @param int $adminUserId
     * @return string The raw (unhashed) code, to be sent to the client
     */
    public function issue(
        string $clientId,
        string $redirectUri,
        string $codeChallenge,
        string $codeChallengeMethod,
        ?string $resource,
        ?string $scope,
        int $adminUserId
    ): string {
        $code = $this->crypto->randomToken();

        $this->codeStorage->save([
            'code_hash' => $this->crypto->hash($code),
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'resource' => $resource,
            'scope' => $scope,
            'admin_user_id' => $adminUserId,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $this->config->getAuthCodeTtl()),
        ]);

        return $code;
    }

    /**
     * Redeems a code, atomically. On any failure throws OauthGrantException whose message
     * is the RFC 6749 error code to return - including revoking whatever was previously
     * issued from this code when a genuine replay (not a same-instant race loser) is
     * detected, per RFC 6749 SS4.1.2.
     *
     * @param string $code
     * @param string $clientId
     * @param string $redirectUri
     * @param string $codeVerifier
     * @return array The authorization-code row
     * @throws OauthGrantException
     */
    public function consume(string $code, string $clientId, string $redirectUri, string $codeVerifier): array
    {
        $codeHash = $this->crypto->hash($code);
        $row = $this->codeStorage->findByCodeHash($codeHash);

        if ($row === null) {
            throw new OauthGrantException('invalid_grant');
        }

        $consumed = $this->codeStorage->markUsedIfUnused($codeHash);

        if (!$consumed) {
            // Re-read current state rather than trusting the pre-update snapshot above: a
            // concurrent request may have just won the race and set used_at/issued_token_entity_id.
            $current = $this->codeStorage->findByCodeHash($codeHash);
            if ($current !== null && $current['used_at'] !== null && $current['issued_token_entity_id'] !== null) {
                $this->accessTokenStorage->revokeFamilyOf((int) $current['issued_token_entity_id']);
            }
            throw new OauthGrantException('invalid_grant');
        }

        if ($row['client_id'] !== $clientId || $row['redirect_uri'] !== $redirectUri) {
            throw new OauthGrantException('invalid_grant');
        }

        $expectedChallenge = $this->crypto->base64UrlEncode(hash('sha256', $codeVerifier, true));
        if (!hash_equals($row['code_challenge'], $expectedChallenge)) {
            throw new OauthGrantException('invalid_grant');
        }

        return $row;
    }

    /**
     * @param string $code
     * @param int $tokenEntityId
     * @return void
     */
    public function recordIssuedToken(string $code, int $tokenEntityId): void
    {
        $this->codeStorage->setIssuedTokenEntityId($this->crypto->hash($code), $tokenEntityId);
    }
}
