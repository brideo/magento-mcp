<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Model\Oauth;

use UpturnStudio\Mcp\Model\ResourceModel\AccessTokenStorage;

/**
 * UpturnStudio_Mcp
 *
 * Validates our own opaque bearer tokens on incoming /mcp calls - a live liveness re-check
 * on every call, not just at issuance (see AdminLivenessChecker).
 */
class TokenAuthenticator
{
    /**
     * @param Crypto $crypto
     * @param AccessTokenStorage $accessTokenStorage
     * @param AdminLivenessChecker $livenessChecker
     */
    public function __construct(
        private readonly Crypto $crypto,
        private readonly AccessTokenStorage $accessTokenStorage,
        private readonly AdminLivenessChecker $livenessChecker
    ) {
    }

    /**
     * @param string|null $authorizationHeader Raw "Authorization" header value
     * @return array|null The token row, if it is a currently-active, unexpired token
     *     belonging to a live admin. Null otherwise.
     */
    public function authenticate(?string $authorizationHeader): ?array
    {
        if ($authorizationHeader === null || $authorizationHeader === '') {
            return null;
        }

        $pieces = explode(' ', trim($authorizationHeader), 2);
        if (count($pieces) !== 2 || strtolower($pieces[0]) !== 'bearer' || $pieces[1] === '') {
            return null;
        }

        $row = $this->accessTokenStorage->findByAccessTokenHash($this->crypto->hash($pieces[1]));
        if ($row === null || $row['status'] !== 'active') {
            return null;
        }
        if (strtotime($row['access_token_expires_at']) <= time()) {
            return null;
        }
        if (!$this->livenessChecker->isActive((int) $row['admin_user_id'])) {
            return null;
        }

        $this->accessTokenStorage->markLastUsed((int) $row['entity_id']);

        return $row;
    }
}
