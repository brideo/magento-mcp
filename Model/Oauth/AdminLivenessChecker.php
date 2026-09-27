<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Model\Oauth;

use Magento\User\Model\UserFactory;

/**
 * UpturnStudio_Mcp
 *
 * Re-checks an admin's live is_active/role-assignment status. This exists specifically
 * because Magento\Integration\Api\UserTokenIssuerInterface::create() performs no such check
 * on its own, and Magento's own admin-disable-revokes-tokens mechanism only catches tokens
 * already minted before the disable event - a freshly-minted token after disable would
 * otherwise sail through untouched. Must be called fresh, uncached, on every use.
 */
class AdminLivenessChecker
{
    /**
     * @param UserFactory $userFactory
     */
    public function __construct(
        private readonly UserFactory $userFactory
    ) {
    }

    /**
     * @param int $adminUserId
     * @return bool
     */
    public function isActive(int $adminUserId): bool
    {
        $user = $this->userFactory->create();
        $user->load($adminUserId);
        if (!$user->getId()) {
            return false;
        }
        return (bool) $user->getIsActive() && $user->hasAssigned2Role($adminUserId);
    }
}
