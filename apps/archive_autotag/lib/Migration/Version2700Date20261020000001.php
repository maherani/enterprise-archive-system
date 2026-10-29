<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version2700Date20261020000001 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('archive_ai_outbox')) {
            $table = $schema->createTable('archive_ai_outbox');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 20,
            ]);
            $table->addColumn('event_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('event_type', Types::STRING, [
                'notnull' => true,
                'length' => 64,
                'default' => 'file.created',
            ]);
            $table->addColumn('file_id', Types::BIGINT, [
                'notnull' => true,
                'length' => 20,
            ]);
            $table->addColumn('file_path', Types::STRING, [
                'notnull' => true,
                'length' => 1024,
            ]);
            $table->addColumn('file_name', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('owner_uid', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('group_name', Types::STRING, [
                'notnull' => true,
                'length' => 128,
                'default' => 'General',
            ]);
            $table->addColumn('mime_type', Types::STRING, [
                'notnull' => true,
                'length' => 128,
            ]);
            $table->addColumn('file_size', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('sha256_hash', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('status', Types::STRING, [
                'notnull' => true,
                'length' => 32,
                'default' => 'PENDING', // PENDING, PUBLISHED, FAILED, DEAD_LETTER
            ]);
            $table->addColumn('retry_count', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('last_error', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('published_at', Types::BIGINT, [
                'notnull' => false,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['event_id'], 'arch_outbox_event_uq');
            $table->addIndex(['status'], 'arch_outbox_status_idx');
            $table->addIndex(['file_id'], 'arch_outbox_file_idx');
            $table->addIndex(['created_at'], 'arch_outbox_created_idx');
        }

        return $schema;
    }
}
