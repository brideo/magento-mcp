<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Model\Oauth;

use Magento\Authorization\Model\ResourceModel\Role\CollectionFactory as RoleCollectionFactory;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Acl\Builder as AclBuilder;

/**
 * UpturnStudio_Mcp
 *
 * Checks whether a specific admin user's own assigned role includes a given ACL resource,
 * without depending on any session or HTTP-request-scoped identity resolution. Needed
 * wherever "which admin" is established by something other than an authenticated admin
 * session or bearer token - the adminhtml /authorize consent screen resolves this itself via
 * the request-scoped $this->_authorization, but the CLI stdio server (Console\Command\
 * ServeCommand) runs with no session at all, so it needs this standalone check instead.
 */
class AdminAclChecker
{
    /**
     * @param RoleCollectionFactory $roleCollectionFactory
     * @param AclBuilder $aclBuilder
     */
    public function __construct(
        private readonly RoleCollectionFactory $roleCollectionFactory,
        private readonly AclBuilder $aclBuilder
    ) {
    }

    /**
     * @param int $adminUserId
     * @param string $aclResource
     * @return bool
     */
    public function isAllowed(int $adminUserId, string $aclResource): bool
    {
        $role = $this->roleCollectionFactory->create()
            ->setUserFilter($adminUserId, UserContextInterface::USER_TYPE_ADMIN)
            ->getFirstItem();
        if (!$role->getId()) {
            return false;
        }

        try {
            return $this->aclBuilder->getAcl()->isAllowed((string) $role->getId(), $aclResource);
        } catch (\Throwable) {
            return false;
        }
    }
}
