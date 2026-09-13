<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1400Date20260913000001 extends SimpleMigrationStep {
    private IDBConnection $connection;

    public function __construct(IDBConnection $connection) {
        $this->connection = $connection;
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // 1. archive_file_ownership
        if (!$schema->hasTable('archive_file_ownership')) {
            $table = $schema->createTable('archive_file_ownership');
            $table->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 11,
            ]);
            $table->addColumn('file_id', 'bigint', [
                'notnull' => true,
            ]);
            $table->addColumn('owner_uid', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('created_at', 'bigint', [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['file_id'], 'arch_file_own_fid_idx');
            $table->addIndex(['owner_uid'], 'arch_file_own_uid_idx');
        }

        // 2. archive_file_grants
        if (!$schema->hasTable('archive_file_grants')) {
            $tableGrants = $schema->createTable('archive_file_grants');
            $tableGrants->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 11,
            ]);
            $tableGrants->addColumn('file_id', 'bigint', [
                'notnull' => true,
            ]);
            $tableGrants->addColumn('grantee_type', 'string', [
                'notnull' => true,
                'length' => 16,
            ]);
            $tableGrants->addColumn('grantee_id', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $tableGrants->addColumn('granted_by', 'string', [
                'notnull' => true,
                'length' => 64,
                'default' => 'admin',
            ]);
            $tableGrants->addColumn('permissions', 'integer', [
                'notnull' => true,
                'default' => 31,
            ]);
            $tableGrants->addColumn('created_at', 'bigint', [
                'notnull' => true,
                'default' => 0,
            ]);
            $tableGrants->setPrimaryKey(['id']);
            $tableGrants->addUniqueIndex(['file_id', 'grantee_type', 'grantee_id'], 'arch_file_grants_unique_idx');
            $tableGrants->addIndex(['grantee_type', 'grantee_id'], 'arch_file_grants_gid_idx');
        }

        // 3. archive_tag_ownership
        if (!$schema->hasTable('archive_tag_ownership')) {
            $tableTag = $schema->createTable('archive_tag_ownership');
            $tableTag->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 11,
            ]);
            $tableTag->addColumn('tag_id', 'bigint', [
                'notnull' => true,
            ]);
            $tableTag->addColumn('owner_uid', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $tableTag->addColumn('created_at', 'bigint', [
                'notnull' => true,
                'default' => 0,
            ]);
            $tableTag->setPrimaryKey(['id']);
            $tableTag->addUniqueIndex(['tag_id'], 'arch_tag_own_tid_idx');
            $tableTag->addIndex(['owner_uid'], 'arch_tag_own_uid_idx');
        }

        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $now = time();

        // Backfill existing system tags as owned by 'system'
        try {
            $qb = $this->connection->getQueryBuilder();
            $qb->select('id')
               ->from('systemtag');
            $res = $qb->executeQuery();
            while ($row = $res->fetchAssociative()) {
                $tagId = (int)$row['id'];
                $checkQb = $this->connection->getQueryBuilder();
                $checkQb->select('id')
                        ->from('archive_tag_ownership')
                        ->where($checkQb->expr()->eq('tag_id', $checkQb->createNamedParameter($tagId)));
                $existing = $checkQb->executeQuery()->fetchAssociative();
                if (!$existing) {
                    $insQb = $this->connection->getQueryBuilder();
                    $insQb->insert('archive_tag_ownership')
                          ->values([
                              'tag_id' => $insQb->createNamedParameter($tagId),
                              'owner_uid' => $insQb->createNamedParameter('system'),
                              'created_at' => $insQb->createNamedParameter($now),
                          ]);
                    $insQb->executeStatement();
                }
            }
        } catch (\Throwable $e) {
            $output->warning('Error backfilling tag ownership: ' . $e->getMessage());
        }

        // Backfill existing archive files as owned by 'admin'
        try {
            $qb = $this->connection->getQueryBuilder();
            $qb->select('fileid')
               ->from('filecache')
               ->where($qb->expr()->like('path', $qb->createNamedParameter('files/Enterprise_Archive/%')))
               ->andWhere($qb->expr()->neq('mimetype', $qb->createNamedParameter(2)));
            $res = $qb->executeQuery();
            while ($row = $res->fetchAssociative()) {
                $fileId = (int)$row['fileid'];
                $checkQb = $this->connection->getQueryBuilder();
                $checkQb->select('id')
                        ->from('archive_file_ownership')
                        ->where($checkQb->expr()->eq('file_id', $checkQb->createNamedParameter($fileId)));
                $existing = $checkQb->executeQuery()->fetchAssociative();
                if (!$existing) {
                    $insQb = $this->connection->getQueryBuilder();
                    $insQb->insert('archive_file_ownership')
                          ->values([
                              'file_id' => $insQb->createNamedParameter($fileId),
                              'owner_uid' => $insQb->createNamedParameter('admin'),
                              'created_at' => $insQb->createNamedParameter($now),
                          ]);
                    $insQb->executeStatement();
                }
            }
        } catch (\Throwable $e) {
            $output->warning('Error backfilling file ownership: ' . $e->getMessage());
        }
    }
}