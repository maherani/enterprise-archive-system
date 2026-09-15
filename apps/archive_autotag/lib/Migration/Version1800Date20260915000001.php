<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1800Date20260915000001 extends SimpleMigrationStep {
    private IDBConnection $connection;

    public function __construct(IDBConnection $connection) {
        $this->connection = $connection;
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('archive_folder_requests')) {
            $table = $schema->createTable('archive_folder_requests');
            $table->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 11,
            ]);
            $table->addColumn('folder_name', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('target_path', 'string', [
                'notnull' => true,
                'length' => 1024,
            ]);
            $table->addColumn('description', 'text', [
                'notnull' => false,
                'default' => '',
            ]);
            $table->addColumn('group_id', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('requester_uid', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('status', 'string', [
                'notnull' => true,
                'length' => 32,
                'default' => 'pending',
            ]);
            $table->addColumn('created_at', 'bigint', [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('updated_at', 'bigint', [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('reviewer_uid', 'string', [
                'notnull' => false,
                'length' => 64,
                'default' => null,
            ]);
            $table->addColumn('reviewed_at', 'bigint', [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('rejection_reason', 'text', [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('error_message', 'text', [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('created_folder_id', 'bigint', [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('created_tag_id', 'bigint', [
                'notnull' => false,
                'default' => null,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['group_id'], 'arch_folder_req_gid_idx');
            $table->addIndex(['status'], 'arch_folder_req_status_idx');
            $table->addIndex(['requester_uid'], 'arch_folder_req_ruid_idx');
        }

        return $schema;
    }
}