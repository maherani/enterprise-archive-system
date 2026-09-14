<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1600Date20260914000001 extends SimpleMigrationStep {
    private IDBConnection $connection;

    public function __construct(IDBConnection $connection) {
        $this->connection = $connection;
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('archive_tag_groups')) {
            $table = $schema->createTable('archive_tag_groups');
            $table->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 11,
            ]);
            $table->addColumn('tag_id', 'bigint', [
                'notnull' => true,
            ]);
            $table->addColumn('group_id', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('created_at', 'bigint', [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['tag_id', 'group_id'], 'arch_tag_grp_uniq_idx');
            $table->addIndex(['group_id'], 'arch_tag_grp_gid_idx');
            $table->addIndex(['tag_id'], 'arch_tag_grp_tid_idx');
        }

        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        // Auto-seed initial group bindings for department tags matching group names
        $groupMappings = [
            'SOC' => 'SOC',
            'CERT' => 'CERT',
            'Network' => 'NetWork',
            'IncedentMNG' => 'IncidentMNG',
            'Compliance_Unit' => 'Compliance_Unit',
        ];

        $now = time();
        foreach ($groupMappings as $tagName => $groupId) {
            try {
                $qb = $this->connection->getQueryBuilder();
                $qb->select('id')
                   ->from('systemtag')
                   ->where($qb->expr()->eq('name', $qb->createNamedParameter($tagName)));
                $row = $qb->executeQuery()->fetchAssociative();
                if ($row) {
                    $tagId = (int)$row['id'];
                    $ins = $this->connection->getQueryBuilder();
                    $ins->insert('archive_tag_groups')
                        ->values([
                            'tag_id' => $ins->createNamedParameter($tagId),
                            'group_id' => $ins->createNamedParameter($groupId),
                            'created_at' => $ins->createNamedParameter($now),
                        ]);
                    $ins->executeStatement();
                }
            } catch (\Throwable $t) {
                // Ignore if already seeded
            }
        }
    }
}
