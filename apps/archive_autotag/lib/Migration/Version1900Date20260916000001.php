<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1900Date20260916000001 extends SimpleMigrationStep {
    private IDBConnection $connection;

    public function __construct(IDBConnection $connection) {
        $this->connection = $connection;
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('archive_folder_request_audit')) {
            $table = $schema->createTable('archive_folder_request_audit');
            $table->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 11,
            ]);
            $table->addColumn('request_id', 'bigint', [
                'notnull' => true,
            ]);
            $table->addColumn('event_type', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('actor_uid', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('group_id', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('folder_name', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('folder_path', 'string', [
                'notnull' => true,
                'length' => 1024,
            ]);
            $table->addColumn('prev_status', 'string', [
                'notnull' => false,
                'length' => 32,
                'default' => null,
            ]);
            $table->addColumn('new_status', 'string', [
                'notnull' => false,
                'length' => 32,
                'default' => null,
            ]);
            $table->addColumn('details', 'text', [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('rejection_reason', 'text', [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('error_info', 'text', [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('created_at', 'bigint', [
                'notnull' => true,
                'default' => 0,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['request_id'], 'arch_fld_aud_rid_idx');
            $table->addIndex(['event_type'], 'arch_fld_aud_ev_idx');
            $table->addIndex(['group_id'], 'arch_fld_aud_gid_idx');
            $table->addIndex(['actor_uid'], 'arch_fld_aud_act_idx');
        }

        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        try {
            // Conditional unique index to strictly block race conditions for concurrent duplicate pending requests
            $this->connection->executeStatement(
                "CREATE UNIQUE INDEX IF NOT EXISTS arch_folder_req_pending_uniq_idx ON oc_archive_folder_requests (group_id, target_path, folder_name) WHERE status = 'pending';"
            );
        } catch (\Throwable $t) {
            // Ignore if index already exists
        }
    }
}
