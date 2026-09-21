<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version2600Date20260921000001 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('archive_document_metadata')) {
            $table = $schema->createTable('archive_document_metadata');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 20,
            ]);
            $table->addColumn('file_id', Types::BIGINT, [
                'notnull' => true,
                'length' => 20,
            ]);
            $table->addColumn('subject', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('document_number', Types::STRING, [
                'notnull' => false,
                'length' => 100,
            ]);
            $table->addColumn('document_date', Types::STRING, [
                'notnull' => false,
                'length' => 32,
            ]);
            $table->addColumn('confidentiality', Types::STRING, [
                'notnull' => true,
                'length' => 32,
                'default' => 'normal',
            ]);
            $table->addColumn('description', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('issuer', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $table->addColumn('created_by', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('updated_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('extra_metadata', Types::TEXT, [
                'notnull' => false,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['file_id'], 'arch_doc_meta_file_idx');
            $table->addIndex(['subject'], 'arch_doc_meta_subj_idx');
            $table->addIndex(['document_number'], 'arch_doc_meta_num_idx');
            $table->addIndex(['confidentiality'], 'arch_doc_meta_conf_idx');
            $table->addIndex(['created_by'], 'arch_doc_meta_user_idx');
        }

        return $schema;
    }
}
