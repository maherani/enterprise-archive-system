<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version2900Date20261026000001 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('archive_ai_outbox')) {
            $table = $schema->getTable('archive_ai_outbox');
            if (!$table->hasColumn('workspace_slug')) {
                $table->addColumn('workspace_slug', Types::STRING, [
                    'notnull' => false,
                    'length' => 255,
                ]);
            }
            if (!$table->hasColumn('workspace_name')) {
                $table->addColumn('workspace_name', Types::STRING, [
                    'notnull' => false,
                    'length' => 255,
                ]);
            }
            if (!$table->hasColumn('old_workspace_slug')) {
                $table->addColumn('old_workspace_slug', Types::STRING, [
                    'notnull' => false,
                    'length' => 255,
                ]);
            }
        }

        return $schema;
    }
}
