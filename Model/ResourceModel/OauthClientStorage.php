<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * UpturnStudio_Mcp
 */
class OauthClientStorage
{
    private const TABLE = 'upturnstudio_mcp_oauth_client';

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @param array $row
     * @return void
     */
    public function save(array $row): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->insert($this->resourceConnection->getTableName(self::TABLE), $row);
    }

    /**
     * @param string $clientId
     * @return array|null
     */
    public function findByClientId(string $clientId): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::TABLE))
            ->where('client_id = ?', $clientId);
        $row = $connection->fetchRow($select);
        return $row ?: null;
    }
}
