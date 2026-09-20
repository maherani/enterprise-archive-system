<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version2400Date20260920000001 extends SimpleMigrationStep {
    private IDBConnection $connection;

    public function __construct(IDBConnection $connection) {
        $this->connection = $connection;
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // 1. Create oc_archive_permission_audit
        if (!$schema->hasTable('archive_permission_audit')) {
            $table = $schema->createTable('archive_permission_audit');
            $table->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 11,
            ]);
            $table->addColumn('request_id', 'string', [
                'notnull' => true,
                'length' => 64,
                'default' => '',
            ]);
            $table->addColumn('correlation_id', 'string', [
                'notnull' => true,
                'length' => 64,
                'default' => '',
            ]);
            $table->addColumn('actor_uid', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('file_id', 'bigint', [
                'notnull' => true,
            ]);
            $table->addColumn('grantee_type', 'string', [
                'notnull' => true,
                'length' => 16,
            ]);
            $table->addColumn('grantee_id', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('action', 'string', [
                'notnull' => true,
                'length' => 32,
            ]);
            $table->addColumn('permissions', 'integer', [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('prev_permissions', 'integer', [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('result', 'string', [
                'notnull' => true,
                'length' => 16,
                'default' => 'success',
            ]);
            $table->addColumn('client_ip', 'string', [
                'notnull' => true,
                'length' => 45,
                'default' => '',
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
            $table->addIndex(['file_id'], 'arch_perm_aud_fid_idx');
            $table->addIndex(['actor_uid'], 'arch_perm_aud_act_idx');
            $table->addIndex(['request_id'], 'arch_perm_aud_rid_idx');
            $table->addIndex(['created_at'], 'arch_perm_aud_time_idx');
        }

        // 2. Enhance oc_archive_tag_audit
        if ($schema->hasTable('archive_tag_audit')) {
            $tableTag = $schema->getTable('archive_tag_audit');
            if (!$tableTag->hasColumn('request_id')) {
                $tableTag->addColumn('request_id', 'string', [
                    'notnull' => false,
                    'length' => 64,
                    'default' => null,
                ]);
                $tableTag->addIndex(['request_id'], 'arch_tag_aud_rid_idx');
            }
            if (!$tableTag->hasColumn('correlation_id')) {
                $tableTag->addColumn('correlation_id', 'string', [
                    'notnull' => false,
                    'length' => 64,
                    'default' => null,
                ]);
                $tableTag->addIndex(['correlation_id'], 'arch_tag_aud_cid_idx');
            }
            if (!$tableTag->hasColumn('client_ip')) {
                $tableTag->addColumn('client_ip', 'string', [
                    'notnull' => false,
                    'length' => 45,
                    'default' => null,
                ]);
            }
            if (!$tableTag->hasColumn('error_info')) {
                $tableTag->addColumn('error_info', 'text', [
                    'notnull' => false,
                    'default' => null,
                ]);
            }
        }

        // 3. Enhance oc_archive_folder_request_audit
        if ($schema->hasTable('archive_folder_request_audit')) {
            $tableFld = $schema->getTable('archive_folder_request_audit');
            if (!$tableFld->hasColumn('correlation_id')) {
                $tableFld->addColumn('correlation_id', 'string', [
                    'notnull' => false,
                    'length' => 64,
                    'default' => null,
                ]);
                $tableFld->addIndex(['correlation_id'], 'arch_fld_aud_cid_idx');
            }
            if (!$tableFld->hasColumn('client_ip')) {
                $tableFld->addColumn('client_ip', 'string', [
                    'notnull' => false,
                    'length' => 45,
                    'default' => null,
                ]);
            }
        }

        // 4. Enhance oc_archive_ai_audit
        if ($schema->hasTable('archive_ai_audit')) {
            $tableAi = $schema->getTable('archive_ai_audit');
            if (!$tableAi->hasColumn('correlation_id')) {
                $tableAi->addColumn('correlation_id', 'string', [
                    'notnull' => false,
                    'length' => 64,
                    'default' => null,
                ]);
                $tableAi->addIndex(['correlation_id'], 'arch_ai_aud_cid_idx');
            }
        }

        return $schema;
    }
}
