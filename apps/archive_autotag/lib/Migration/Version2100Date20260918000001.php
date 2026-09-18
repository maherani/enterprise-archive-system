<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version2100Date20260918000001 extends SimpleMigrationStep {
    private IDBConnection $connection;

    public function __construct(IDBConnection $connection) {
        $this->connection = $connection;
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('archive_tag_audit')) {
            $table = $schema->createTable('archive_tag_audit');
            $table->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 11,
            ]);
            $table->addColumn('actor_uid', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('group_id', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('action', 'string', [
                'notnull' => true,
                'length' => 32,
            ]);
            $table->addColumn('tag_id', 'bigint', [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('tag_name', 'string', [
                'notnull' => true,
                'length' => 128,
                'default' => '',
            ]);
            $table->addColumn('target_type', 'string', [
                'notnull' => false,
                'length' => 16,
                'default' => null,
            ]);
            $table->addColumn('target_id', 'bigint', [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('target_path', 'text', [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('result', 'string', [
                'notnull' => true,
                'length' => 16,
                'default' => 'success',
            ]);
            $table->addColumn('details', 'text', [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('created_at', 'bigint', [
                'notnull' => true,
                'default' => 0,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['actor_uid'], 'arch_tag_aud_act_idx');
            $table->addIndex(['group_id'], 'arch_tag_aud_grp_idx');
        }

        return $schema;
    }
}
