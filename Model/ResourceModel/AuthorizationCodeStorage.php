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
class AuthorizationCodeStorage
{
    private const TABLE = 'upturnstudio_mcp_authorization_code';

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @param array $row
     * @return int Inserted entity_id
     */
    public function save(array $row): int
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName(self::TABLE);
        $connection->insert($tableName, $row);
        return (int) $connection->lastInsertId($tableName);
    }

    /**
     * @param string $codeHash
     * @return array|null
     */
    public function findByCodeHash(string $codeHash): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::TABLE))
            ->where('code_hash = ?', $codeHash);
        $row = $connection->fetchRow($select);
        return $row ?: null;
    }

    /**
     * Atomically marks a code used in a single UPDATE, guarding against a concurrent
     * redemption race (check-then-update would allow two concurrent requests to both see
     * "unused"). Returns true only if this call is the one that consumed it.
     *
     * @param string $codeHash
     * @return bool
     */
    public function markUsedIfUnused(string $codeHash): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName(self::TABLE);
        $now = gmdate('Y-m-d H:i:s');
        $where = $connection->quoteInto('code_hash = ?', $codeHash)
            . ' AND used_at IS NULL AND '
            . $connection->quoteInto('expires_at > ?', $now);
        $affectedRows = $connection->update($tableName, ['used_at' => $now], $where);
        return $affectedRows === 1;
    }

    /**
     * Records which access-token row a code produced, so a later replay of the same
     * (already-used) code can revoke that row per RFC 6749 SS4.1.2.
     *
     * @param string $codeHash
     * @param int $tokenEntityId
     * @return void
     */
    public function setIssuedTokenEntityId(string $codeHash, int $tokenEntityId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->update(
            $this->resourceConnection->getTableName(self::TABLE),
            ['issued_token_entity_id' => $tokenEntityId],
            $connection->quoteInto('code_hash = ?', $codeHash)
        );
    }

    /**
     * @return int Number of rows deleted
     */
    public function purgeExpired(): int
    {
        $connection = $this->resourceConnection->getConnection();
        return (int) $connection->delete(
            $this->resourceConnection->getTableName(self::TABLE),
            $connection->quoteInto('expires_at < ?', gmdate('Y-m-d H:i:s'))
        );
    }
}
