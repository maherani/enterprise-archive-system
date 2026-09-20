<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version2500Date20260920000001 extends SimpleMigrationStep {
    private IDBConnection $connection;

    public function __construct(IDBConnection $connection) {
        $this->connection = $connection;
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('archive_ai_audit')) {
            $table = $schema->getTable('archive_ai_audit');

            if (!$table->hasColumn('bytes_requested')) {
                $table->addColumn('bytes_requested', 'bigint', [
                    'notnull' => true,
                    'default' => 0,
                ]);
            }

            if (!$table->hasColumn('transfer_status')) {
                $table->addColumn('transfer_status', 'string', [
                    'notnull' => true,
                    'length' => 32,
                    'default' => 'NONE',
                ]);
                $table->addIndex(['transfer_status'], 'arch_ai_aud_tstat_idx');
            }

            if (!$table->hasColumn('stage')) {
                $table->addColumn('stage', 'string', [
                    'notnull' => true,
                    'length' => 32,
                    'default' => 'INIT',
                ]);
                $table->addIndex(['stage'], 'arch_ai_aud_stage_idx');
            }

            if (!$table->hasColumn('duration_ms')) {
                $table->addColumn('duration_ms', 'integer', [
                    'notnull' => false,
                    'default' => 0,
                ]);
            }
        }

        return $schema;
    }
}
