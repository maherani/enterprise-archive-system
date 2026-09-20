<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version2200Date20260920000001 extends SimpleMigrationStep {
    private IDBConnection $connection;

    public function __construct(IDBConnection $connection) {
        $this->connection = $connection;
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // 1. archive_ai_services
        if (!$schema->hasTable('archive_ai_services')) {
            $table = $schema->createTable('archive_ai_services');
            $table->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 11,
            ]);
            $table->addColumn('service_id', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('display_name', 'string', [
                'notnull' => true,
                'length' => 128,
            ]);
            $table->addColumn('description', 'text', [
                'notnull' => false,
                'default' => '',
            ]);
            $table->addColumn('default_actor_uid', 'string', [
                'notnull' => true,
                'length' => 64,
                'default' => 'api_worker',
            ]);
            $table->addColumn('delegation_policy', 'string', [
                'notnull' => true,
                'length' => 32,
                'default' => 'DENY_ALL',
            ]);
            $table->addColumn('allow_admin_delegation', 'boolean', [
                'notnull' => true,
                'default' => false,
            ]);
            $table->addColumn('is_active', 'boolean', [
                'notnull' => true,
                'default' => true,
            ]);
            $table->addColumn('created_at', 'bigint', [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('updated_at', 'bigint', [
                'notnull' => true,
                'default' => 0,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['service_id'], 'arch_ai_svc_id_idx');
        }

        // 2. archive_ai_tokens
        if (!$schema->hasTable('archive_ai_tokens')) {
            $tableTokens = $schema->createTable('archive_ai_tokens');
            $tableTokens->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 11,
            ]);
            $tableTokens->addColumn('service_id', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $tableTokens->addColumn('token_name', 'string', [
                'notnull' => true,
                'length' => 128,
            ]);
            $tableTokens->addColumn('token_prefix', 'string', [
                'notnull' => true,
                'length' => 16,
            ]);
            $tableTokens->addColumn('token_hash', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $tableTokens->addColumn('status', 'string', [
                'notnull' => true,
                'length' => 32,
                'default' => 'ACTIVE',
            ]);
            $tableTokens->addColumn('expires_at', 'bigint', [
                'notnull' => false,
                'default' => null,
            ]);
            $tableTokens->addColumn('grace_period_until', 'bigint', [
                'notnull' => false,
                'default' => null,
            ]);
            $tableTokens->addColumn('last_used_at', 'bigint', [
                'notnull' => false,
                'default' => null,
            ]);
            $tableTokens->addColumn('last_used_ip', 'string', [
                'notnull' => false,
                'length' => 45,
                'default' => null,
            ]);
            $tableTokens->addColumn('created_by', 'string', [
                'notnull' => true,
                'length' => 64,
                'default' => 'system',
            ]);
            $tableTokens->addColumn('created_at', 'bigint', [
                'notnull' => true,
                'default' => 0,
            ]);

            $tableTokens->setPrimaryKey(['id']);
            $tableTokens->addUniqueIndex(['token_hash'], 'arch_ai_tok_hsh_idx');
            $tableTokens->addIndex(['token_prefix'], 'arch_ai_tok_pfx_idx');
            $tableTokens->addIndex(['service_id'], 'arch_ai_tok_svc_idx');
            $tableTokens->addIndex(['status'], 'arch_ai_tok_stat_idx');
        }

        // 3. archive_ai_delegations
        if (!$schema->hasTable('archive_ai_delegations')) {
            $tableDel = $schema->createTable('archive_ai_delegations');
            $tableDel->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 11,
            ]);
            $tableDel->addColumn('service_id', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $tableDel->addColumn('subject_type', 'string', [
                'notnull' => true,
                'length' => 16,
            ]);
            $tableDel->addColumn('subject_id', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $tableDel->addColumn('created_at', 'bigint', [
                'notnull' => true,
                'default' => 0,
            ]);

            $tableDel->setPrimaryKey(['id']);
            $tableDel->addUniqueIndex(['service_id', 'subject_type', 'subject_id'], 'arch_ai_del_uniq_idx');
            $tableDel->addIndex(['service_id'], 'arch_ai_del_svc_idx');
        }

        // 4. Update archive_ai_audit if table exists
        if ($schema->hasTable('archive_ai_audit')) {
            $tableAudit = $schema->getTable('archive_ai_audit');
            if (!$tableAudit->hasColumn('service_id')) {
                $tableAudit->addColumn('service_id', 'string', [
                    'notnull' => true,
                    'length' => 64,
                    'default' => 'unknown',
                ]);
            }
            if (!$tableAudit->hasColumn('token_id')) {
                $tableAudit->addColumn('token_id', 'bigint', [
                    'notnull' => false,
                    'default' => null,
                ]);
            }
            if (!$tableAudit->hasColumn('delegation_requested')) {
                $tableAudit->addColumn('delegation_requested', 'string', [
                    'notnull' => false,
                    'length' => 64,
                    'default' => null,
                ]);
            }
            if (!$tableAudit->hasColumn('delegation_status')) {
                $tableAudit->addColumn('delegation_status', 'string', [
                    'notnull' => true,
                    'length' => 32,
                    'default' => 'NONE',
                ]);
            }
        }

        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $now = time();

        try {
            // Check if default_ai_service already exists
            $qb = $this->connection->getQueryBuilder();
            $qb->select('id')
               ->from('archive_ai_services')
               ->where($qb->expr()->eq('service_id', $qb->createNamedParameter('default_ai_service')));
            $existingSvc = $qb->executeQuery()->fetchAssociative();

            if (!$existingSvc) {
                // Insert default_ai_service
                $insSvc = $this->connection->getQueryBuilder();
                $insSvc->insert('archive_ai_services')
                       ->values([
                           'service_id' => $insSvc->createNamedParameter('default_ai_service'),
                           'display_name' => $insSvc->createNamedParameter('Default AI Integration Service'),
                           'description' => $insSvc->createNamedParameter('Standard departmental AI service with strict delegation allowlist'),
                           'default_actor_uid' => $insSvc->createNamedParameter('api_worker'),
                           'delegation_policy' => $insSvc->createNamedParameter('SPECIFIC_GROUPS'),
                           'allow_admin_delegation' => $insSvc->createNamedParameter(false, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL),
                           'is_active' => $insSvc->createNamedParameter(true, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL),
                           'created_at' => $insSvc->createNamedParameter($now),
                           'updated_at' => $insSvc->createNamedParameter($now),
                       ]);
                $insSvc->executeStatement();

                // Seed permitted delegation groups for default_ai_service
                $groups = ['Compliance_Unit', 'SOC', 'CERT', 'Network', 'IncidentMNG'];
                foreach ($groups as $grp) {
                    $insDel = $this->connection->getQueryBuilder();
                    $insDel->insert('archive_ai_delegations')
                           ->values([
                               'service_id' => $insDel->createNamedParameter('default_ai_service'),
                               'subject_type' => $insDel->createNamedParameter('GROUP'),
                               'subject_id' => $insDel->createNamedParameter($grp),
                               'created_at' => $insDel->createNamedParameter($now),
                           ]);
                    $insDel->executeStatement();
                }

                // Check for legacy token in appconfig and seed into archive_ai_tokens
                $cfgQb = $this->connection->getQueryBuilder();
                $cfgQb->select('configvalue')
                      ->from('appconfig')
                      ->where($cfgQb->expr()->eq('appid', $cfgQb->createNamedParameter('archive_autotag')))
                      ->andWhere($cfgQb->expr()->eq('configkey', $cfgQb->createNamedParameter('ai_service_token')));
                $legacyToken = (string)$cfgQb->executeQuery()->fetchOne();

                if ($legacyToken !== '') {
                    $prefix = substr($legacyToken, 0, 12);
                    $hash = hash('sha256', $legacyToken);

                    $insTok = $this->connection->getQueryBuilder();
                    $insTok->insert('archive_ai_tokens')
                           ->values([
                               'service_id' => $insTok->createNamedParameter('default_ai_service'),
                               'token_name' => $insTok->createNamedParameter('Legacy Primary Service Token'),
                               'token_prefix' => $insTok->createNamedParameter($prefix),
                               'token_hash' => $insTok->createNamedParameter($hash),
                               'status' => $insTok->createNamedParameter('ACTIVE'),
                               'created_by' => $insTok->createNamedParameter('migration_v2200'),
                               'created_at' => $insTok->createNamedParameter($now),
                           ]);
                    $insTok->executeStatement();
                }
            }
        } catch (\Throwable $e) {
            $output->warning('Migration Version2200 postSchemaChange error: ' . $e->getMessage());
        }
    }
}
