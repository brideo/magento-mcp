<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Model\Oauth;

use UpturnStudio\Mcp\Model\Config;
use UpturnStudio\Mcp\Model\ResourceModel\AccessTokenStorage;

/**
 * UpturnStudio_Mcp
 *
 * Mints our own opaque access/refresh token pairs - never Magento's native admin bearer
 * token - so a token leaked from the client side is meaningless against Magento's real
 * /rest or /graphql endpoints.
 */
class TokenIssuer
{
    private const SCOPE = 'graphql:read';

    /**
     * How long after a rotation an old (now-superseded) refresh token is still tolerated as
     * a benign retry (Claude's own documented behaviour includes proactive refreshes and
     * retries on a lost response) rather than treated as theft.
     */
    private const REUSE_GRACE_WINDOW_SECONDS = 60;

    /**
     * @param Crypto $crypto
     * @param Config $config
     * @param AccessTokenStorage $accessTokenStorage
     * @param AdminLivenessChecker $livenessChecker
     */
    public function __construct(
        private readonly Crypto $crypto,
        private readonly Config $config,
        private readonly AccessTokenStorage $accessTokenStorage,
        private readonly AdminLivenessChecker $livenessChecker
    ) {
    }

    /**
     * @param string $clientId
     * @param int $adminUserId
     * @param string|null $resource
     * @return array{entity_id: int, response: array}
     * @throws OauthGrantException With message "invalid_grant" if the admin is no longer active
     */
    public function issue(string $clientId, int $adminUserId, ?string $resource): array
    {
        if (!$this->livenessChecker->isActive($adminUserId)) {
            throw new OauthGrantException('invalid_grant');
        }

        $accessToken = $this->crypto->randomToken();
        $refreshToken = $this->crypto->randomToken();
        $now = time();

        $entityId = $this->accessTokenStorage->save([
            'access_token_hash' => $this->crypto->hash($accessToken),
            'refresh_token_hash' => $this->crypto->hash($refreshToken),
            'client_id' => $clientId,
            'admin_user_id' => $adminUserId,
            'resource' => $resource,
            'scope' => self::SCOPE,
            'family_id' => $this->crypto->randomToken(),
            'status' => 'active',
            'access_token_expires_at' => gmdate('Y-m-d H:i:s', $now + $this->config->getAccessTokenTtl()),
            'refresh_token_expires_at' => gmdate('Y-m-d H:i:s', $now + $this->config->getRefreshTokenTtl()),
        ]);

        return ['entity_id' => $entityId, 'response' => $this->toResponse($accessToken, $refreshToken)];
    }

    /**
     * Redeems a presented refresh token, atomically. Handles all three cases: normal
     * rotation, a benign same-generation retry within the grace window, and confirmed reuse
     * of a dead credential (which revokes the entire token family and forces re-consent).
     *
     * @param string $presentedRefreshToken
     * @return array{entity_id: int, response: array}
     * @throws OauthGrantException With message "invalid_grant" on any failure
     */
    public function redeemRefreshToken(string $presentedRefreshToken): array
    {
        $row = $this->accessTokenStorage->findByRefreshTokenHash($this->crypto->hash($presentedRefreshToken));
        if ($row === null) {
            throw new OauthGrantException('invalid_grant');
        }

        // Same admin_user_id for every row in a rotation family, so one check up front
        // covers both the "active" and "grace-window retry" branches below.
        if (!$this->livenessChecker->isActive((int) $row['admin_user_id'])) {
            throw new OauthGrantException('invalid_grant');
        }

        if ($row['status'] === 'active') {
            return $this->rotate($row);
        }

        if ($row['status'] === 'rotated' && $row['replaced_by_entity_id'] !== null) {
            $nextRow = $this->accessTokenStorage->findById((int) $row['replaced_by_entity_id']);
            $withinGraceWindow = strtotime($row['updated_at']) >= (time() - self::REUSE_GRACE_WINDOW_SECONDS);
            if ($nextRow !== null && $nextRow['status'] === 'active' && $withinGraceWindow) {
                // Benign retry: the client never saw the response from the rotation that
                // superseded this token. There is nothing to "resend" (only hashes are
                // stored) - rotating again from the current tip hands it a fresh, valid pair.
                return $this->rotate($nextRow);
            }
        }

        // status === 'revoked', or a stale/broken chain, or outside the grace window: treat
        // as confirmed reuse of a dead credential and kill the whole family.
        $this->accessTokenStorage->revokeFamily($row['family_id']);
        throw new OauthGrantException('invalid_grant');
    }

    /**
     * @param array $oldRow The currently-active row being rotated
     * @return array{entity_id: int, response: array}
     */
    private function rotate(array $oldRow): array
    {
        $accessToken = $this->crypto->randomToken();
        $refreshToken = $this->crypto->randomToken();
        $now = time();

        $newEntityId = $this->accessTokenStorage->rotate((int) $oldRow['entity_id'], [
            'access_token_hash' => $this->crypto->hash($accessToken),
            'refresh_token_hash' => $this->crypto->hash($refreshToken),
            'client_id' => $oldRow['client_id'],
            'admin_user_id' => $oldRow['admin_user_id'],
            'resource' => $oldRow['resource'],
            'scope' => $oldRow['scope'],
            'family_id' => $oldRow['family_id'],
            'status' => 'active',
            'access_token_expires_at' => gmdate('Y-m-d H:i:s', $now + $this->config->getAccessTokenTtl()),
            'refresh_token_expires_at' => gmdate('Y-m-d H:i:s', $now + $this->config->getRefreshTokenTtl()),
        ]);

        return ['entity_id' => $newEntityId, 'response' => $this->toResponse($accessToken, $refreshToken)];
    }

    /**
     * @param string $accessToken
     * @param string $refreshToken
     * @return array
     */
    private function toResponse(string $accessToken, string $refreshToken): array
    {
        return [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $this->config->getAccessTokenTtl(),
            'refresh_token' => $refreshToken,
            'scope' => self::SCOPE,
        ];
    }
}
