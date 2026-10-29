<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version2800Date20261026000001 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('archive_ai_outbox')) {
            $table = $schema->getTable('archive_ai_outbox');
            if (!$table->hasColumn('scope')) {
                $table->addColumn('scope', Types::STRING, [
                    'notnull' => true,
                    'length' => 32,
                    'default' => 'group',
                ]);
            }
        }

        return $schema;
    }
}
