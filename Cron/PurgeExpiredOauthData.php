<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Cron;

use Psr\Log\LoggerInterface;
use UpturnStudio\Mcp\Model\ResourceModel\AccessTokenStorage;
use UpturnStudio\Mcp\Model\ResourceModel\AuthorizationCodeStorage;

/**
 * UpturnStudio_Mcp
 *
 * Daily cleanup of expired/used authorization codes and fully-expired token rows. These
 * tables hold time-bound secrets, so pruning them is more than housekeeping.
 */
class PurgeExpiredOauthData
{
    /**
     * @param AuthorizationCodeStorage $codeStorage
     * @param AccessTokenStorage $accessTokenStorage
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly AuthorizationCodeStorage $codeStorage,
        private readonly AccessTokenStorage $accessTokenStorage,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        try {
            $codesDeleted = $this->codeStorage->purgeExpired();
            $tokensDeleted = $this->accessTokenStorage->purgeExpired();
            $this->logger->info(sprintf(
                'UpturnStudio_Mcp: purged %d expired authorization codes and %d expired token rows.',
                $codesDeleted,
                $tokensDeleted
            ));
        } catch (\Throwable $e) {
            $this->logger->error('UpturnStudio_Mcp: scheduled purge failed - ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
