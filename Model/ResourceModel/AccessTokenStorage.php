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
class AccessTokenStorage
{
    private const TABLE = 'upturnstudio_mcp_access_token';

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
     * @param int $entityId
     * @return array|null
     */
    public function findById(int $entityId): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::TABLE))
            ->where('entity_id = ?', $entityId);
        $row = $connection->fetchRow($select);
        return $row ?: null;
    }

    /**
     * @param string $accessTokenHash
     * @return array|null
     */
    public function findByAccessTokenHash(string $accessTokenHash): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::TABLE))
            ->where('access_token_hash = ?', $accessTokenHash);
        $row = $connection->fetchRow($select);
        return $row ?: null;
    }

    /**
     * @param string $refreshTokenHash
     * @return array|null
     */
    public function findByRefreshTokenHash(string $refreshTokenHash): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::TABLE))
            ->where('refresh_token_hash = ?', $refreshTokenHash);
        $row = $connection->fetchRow($select);
        return $row ?: null;
    }

    /**
     * Atomically rotates a refresh token: inserts the new row, then marks the old row
     * rotated and points it at the new row, in one transaction.
     *
     * @param int $oldEntityId
     * @param array $newRow
     * @return int New entity_id
     */
    public function rotate(int $oldEntityId, array $newRow): int
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName(self::TABLE);
        $connection->beginTransaction();
        try {
            $connection->insert($tableName, $newRow);
            $newEntityId = (int) $connection->lastInsertId($tableName);
            $connection->update(
                $tableName,
                ['status' => 'rotated', 'replaced_by_entity_id' => $newEntityId],
                $connection->quoteInto('entity_id = ?', $oldEntityId)
            );
            $connection->commit();
            return $newEntityId;
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * Revokes every row sharing a family_id (used both for an explicit admin revoke and for
     * confirmed refresh-token-theft response).
     *
     * @param string $familyId
     * @return void
     */
    public function revokeFamily(string $familyId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->update(
            $this->resourceConnection->getTableName(self::TABLE),
            ['status' => 'revoked', 'revoked_at' => gmdate('Y-m-d H:i:s')],
            $connection->quoteInto('family_id = ?', $familyId)
        );
    }

    /**
     * Revokes the family that a specific token row belongs to (used when reacting to an
     * authorization-code replay - RFC 6749 SS4.1.2 requires revoking previously-issued tokens).
     *
     * @param int $entityId
     * @return void
     */
    public function revokeFamilyOf(int $entityId): void
    {
        $row = $this->findById($entityId);
        if ($row !== null) {
            $this->revokeFamily($row['family_id']);
        }
    }

    /**
     * @param int $entityId
     * @return void
     */
    public function markLastUsed(int $entityId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->update(
            $this->resourceConnection->getTableName(self::TABLE),
            ['last_used_at' => gmdate('Y-m-d H:i:s')],
            $connection->quoteInto('entity_id = ?', $entityId)
        );
    }

    /**
     * Active connections for the "Connected Apps" admin screen: one row per currently-active
     * token family, joined with client name and admin username.
     *
     * @return array
     */
    public function fetchActiveConnections(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['t' => $this->resourceConnection->getTableName(self::TABLE)])
            ->joinLeft(
                ['c' => $this->resourceConnection->getTableName('upturnstudio_mcp_oauth_client')],
                't.client_id = c.client_id',
                ['client_name']
            )
            ->joinLeft(
                ['u' => $this->resourceConnection->getTableName('admin_user')],
                't.admin_user_id = u.user_id',
                ['username', 'firstname', 'lastname']
            )
            ->where('t.status = ?', 'active')
            ->order('t.created_at DESC');
        return $connection->fetchAll($select);
    }

    /**
     * @return int Number of rows deleted
     */
    public function purgeExpired(): int
    {
        $connection = $this->resourceConnection->getConnection();
        return (int) $connection->delete(
            $this->resourceConnection->getTableName(self::TABLE),
            $connection->quoteInto('refresh_token_expires_at < ?', gmdate('Y-m-d H:i:s'))
        );
    }
}
