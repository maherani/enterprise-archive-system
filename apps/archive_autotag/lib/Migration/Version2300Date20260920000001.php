<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version2300Date20260920000001 extends SimpleMigrationStep {
    private IDBConnection $connection;

    public function __construct(IDBConnection $connection) {
        $this->connection = $connection;
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('archive_tag_ownership')) {
            $table = $schema->getTable('archive_tag_ownership');
            if (!$table->hasColumn('status')) {
                $table->addColumn('status', 'string', [
                    'notnull' => true,
                    'length' => 32,
                    'default' => 'ACTIVE',
                ]);
                $table->addIndex(['status'], 'arch_tag_own_stat_idx');
            }
        }

        return $schema;
    }
}
