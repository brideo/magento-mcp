<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Model\Oauth;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Integration\Api\UserTokenIssuerInterface;
use Magento\Integration\Model\CustomUserContext;
use Magento\Integration\Model\UserToken\UserTokenParametersFactory;
use Psr\Log\LoggerInterface;

/**
 * UpturnStudio_Mcp
 *
 * Mints a fresh, genuine Magento admin bearer token for a known admin user ID - server-side
 * only, never exposed to the OAuth client, used once per GraphQL call and then discarded.
 * Deliberately not cached: UserTokenIssuerInterface::create() performs no liveness check of
 * its own, so a fresh liveness check is required on every mint regardless of caching, which
 * makes caching pure downside (a persistent copy of a full-privilege credential) for no
 * upside.
 */
class MagentoAdminTokenProvider
{
    /**
     * @param UserTokenIssuerInterface $userTokenIssuer
     * @param UserTokenParametersFactory $tokenParametersFactory
     * @param AdminLivenessChecker $livenessChecker
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly UserTokenIssuerInterface $userTokenIssuer,
        private readonly UserTokenParametersFactory $tokenParametersFactory,
        private readonly AdminLivenessChecker $livenessChecker,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param int $adminUserId
     * @return string|null Null if the admin is no longer active, or minting failed
     */
    public function getTokenFor(int $adminUserId): ?string
    {
        if (!$this->livenessChecker->isActive($adminUserId)) {
            return null;
        }

        try {
            $context = new CustomUserContext($adminUserId, UserContextInterface::USER_TYPE_ADMIN);
            $params = $this->tokenParametersFactory->create();
            return $this->userTokenIssuer->create($context, $params);
        } catch (\Throwable $e) {
            $this->logger->error(
                'UpturnStudio_Mcp: failed to mint internal Magento admin token - ' . $e->getMessage(),
                ['exception' => $e]
            );
            return null;
        }
    }
}
