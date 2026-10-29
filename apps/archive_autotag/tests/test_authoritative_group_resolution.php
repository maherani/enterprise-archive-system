<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Tests;

// Autoload Nextcloud and 3rdparty classes
require_once '/var/www/html/3rdparty/autoload.php';
require_once '/var/www/html/lib/composer/autoload.php';
require_once __DIR__ . '/../lib/Service/AiOutboxService.php';

use OCA\ArchiveAutoTag\Service\AiOutboxService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\ConflictResolutionMode;
use OCP\DB\IResult;
use OCP\Files\File;
use OCP\Files\Cache\ICacheEntry;
use OC\Files\Cache\CacheEntry;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use Psr\Log\LoggerInterface;

// =========================================================================
// Complete Stubs for Nextcloud Architecture Testing
// =========================================================================

class StubFile implements File {
    public function __construct(
        private int $id,
        private string $path,
        private string $name,
        private int $size = 100,
        private string $content = "Sample document text content for AI indexing.",
        private string $mime = "text/plain"
    ) {}

    public function getId(): int { return $this->id; }
    public function getPath(): string { return $this->path; }
    public function getName(): string { return $this->name; }
    public function getSize($includeMounts = true): int { return $this->size; }
    public function getContent(): string { return $this->content; }
    public function getMimeType(): string { return $this->mime; }
    public function putContent($data) { return strlen((string)$data); }
    public function fopen($mode) { return false; }
    public function hash($type, $raw = false) { return ''; }
    public function getChecksum() { return ''; }
    public function getExtension(): string { return pathinfo($this->name, PATHINFO_EXTENSION); }
    public function move($targetPath) { return $this; }
    public function delete() {}
    public function copy($targetPath) { return $this; }
    public function touch($mtime = null) {}
    public function getStorage() { return null; }
    public function getInternalPath(): string { return $this->path; }
    public function stat() { return []; }
    public function getMTime() { return time(); }
    public function getEtag() { return ''; }
    public function getPermissions() { return 31; }
    public function isReadable() { return true; }
    public function isUpdateable() { return true; }
    public function isDeletable() { return true; }
    public function isShareable() { return true; }
    public function getParent() { return null; }
    public function lock($type) {}
    public function changeLock($targetType) {}
    public function unlock($type) {}
    public function getMimePart(): string { return 'text'; }
    public function isEncrypted(): bool { return false; }
    public function getType(): string { return 'file'; }
    public function isCreatable(): bool { return true; }
    public function isShared(): bool { return false; }
    public function isMounted(): bool { return false; }
    public function getMountPoint() { return null; }
    public function getOwner() { return null; }
    public function getCreationTime(): int { return time(); }
    public function getUploadTime(): int { return time(); }
    public function getLastActivity(): int { return time(); }
    public function getParentId(): int { return 0; }
    public function getMetadata(): array { return []; }
    public function getData(): ICacheEntry { return new CacheEntry(); }
}

class StubGroup implements IGroup {
    public function __construct(
        private string $gid,
        private string $displayName = ''
    ) {}

    public function getGID(): string { return $this->gid; }
    public function getDisplayName(): string { return $this->displayName ?: $this->gid; }
    public function setDisplayName(string $displayName): bool { $this->displayName = $displayName; return true; }
    public function getUsers(): array { return []; }
    public function inGroup($user): bool { return true; }
    public function addUser($user): void {}
    public function removeUser($user): void {}
    public function searchUsers($search = '', $limit = null, $offset = null): array { return []; }
    public function count($search = ''): int { return 1; }
    public function countDisabled(): int { return 0; }
    public function searchDisplayName(string $search, ?int $limit = null, ?int $offset = null): array { return []; }
    public function getBackendNames(): array { return ['database']; }
    public function delete(): bool { return true; }
    public function canRemoveUser(): bool { return true; }
    public function canAddUser(): bool { return true; }
    public function hideFromCollaboration(): bool { return false; }
}

class StubGroupManager implements IGroupManager {
    /** @var array<string, StubGroup> */
    private array $groups = [];

    public function addGroup(string $gid, string $displayName = ''): StubGroup {
        $g = new StubGroup($gid, $displayName);
        $this->groups[$gid] = $g;
        return $g;
    }

    public function groupExists($gid): bool {
        return isset($this->groups[(string)$gid]);
    }

    public function get($gid): ?IGroup {
        return $this->groups[(string)$gid] ?? null;
    }

    public function getDisplayName(string $groupId): ?string {
        $g = $this->get($groupId);
        return $g ? $g->getDisplayName() : null;
    }

    public function isBackendUsed($backendClass) { return true; }
    public function addBackend($backend) {}
    public function clearBackends() {}
    public function getBackends() { return []; }
    public function createGroup($gid) { return true; }
    public function search(string $search, ?int $limit = null, ?int $offset = null) { return array_values($this->groups); }
    public function getUserGroups(?IUser $user = null) { return []; }
    public function getUserGroupIds(IUser $user): array { return []; }
    public function displayNamesInGroup($gid, $search = null, $limit = null, $offset = null) { return []; }
    public function isAdmin($userId) { return false; }
    public function isDelegatedAdmin(string $userId): bool { return false; }
    public function isInGroup($userId, $group) { return false; }
}

class StubResult implements IResult {
    public function __construct(private array $rows) {}
    public function closeCursor(): bool { return true; }
    public function fetch(?int $fetchMode = null) { return $this->rows[0] ?? false; }
    public function fetchAssociative(): array|false { return $this->rows[0] ?? false; }
    public function fetchNumeric(): array|false { return isset($this->rows[0]) ? array_values($this->rows[0]) : false; }
    public function fetchOne() { return isset($this->rows[0]) ? reset($this->rows[0]) : false; }
    public function fetchAll(?int $fetchMode = null): array { return $this->rows; }
    public function fetchAllAssociative(): array { return $this->rows; }
    public function fetchAllNumeric(): array { return array_map('array_values', $this->rows); }
    public function fetchFirstColumn(): array { return array_column($this->rows, 0); }
    public function fetchColumn() { return isset($this->rows[0]) ? reset($this->rows[0]) : false; }
    public function rowCount(): int { return count($this->rows); }
    public function iterateNumeric(): \Traversable { yield from $this->fetchAllNumeric(); }
    public function iterateAssociative(): \Traversable { yield from $this->rows; }
}

class StubQueryBuilder implements IQueryBuilder {
    private array $dbTable = [];
    public ?array $lastInserted = null;
    public string $selectFields = '';
    public string $fromTable = '';
    public array $whereConditions = [];

    public function __construct(private array &$dbRef) {
        $this->dbTable = &$dbRef;
    }

    public function select(...$selects) {
        $this->selectFields = implode(',', $selects);
        return $this;
    }

    public function from($from, $alias = null) {
        $this->fromTable = (string)$from;
        return $this;
    }

    public function where(...$predicates) {
        foreach ($predicates as $p) {
            $this->whereConditions[] = $p;
        }
        return $this;
    }

    public function andWhere(...$where) {
        foreach ($where as $w) {
            $this->whereConditions[] = $w;
        }
        return $this;
    }

    public function insert($insert = null) {
        if ($insert) {
            $this->fromTable = (string)$insert;
        }
        return $this;
    }

    public function values(array $values) {
        $this->lastInserted = $values;
        return $this;
    }

    public function createNamedParameter($value, $type = self::PARAM_STR, $placeHolder = null) {
        return (string)$value;
    }

    public function expr() {
        return new class {
            public function eq($x, $y) { return ['op' => 'eq', 'col' => $x, 'val' => $y]; }
            public function orX(...$x) { return ['op' => 'or', 'conds' => $x]; }
            public function in($x, $y) { return ['op' => 'in', 'col' => $x, 'val' => $y]; }
            public function lt($x, $y) { return ['op' => 'lt', 'col' => $x, 'val' => $y]; }
            public function cast($x, $y) { return $x; }
        };
    }

    public function executeQuery(?IDBConnection $connection = null): IResult {
        $rows = [];
        if ($this->fromTable === 'groups') {
            $tableData = $this->dbTable['groups'] ?? [];
            foreach ($tableData as $row) {
                $match = true;
                foreach ($this->whereConditions as $cond) {
                    if (is_array($cond) && ($cond['op'] ?? '') === 'eq' && $cond['col'] === 'displayname') {
                        if ($row['displayname'] !== $cond['val']) {
                            $match = false;
                        }
                    }
                }
                if ($match) {
                    $rows[] = $row;
                }
            }
        }

        return new StubResult($rows);
    }

    public function executeStatement(?IDBConnection $connection = null): int {
        if ($this->lastInserted) {
            $this->dbTable[$this->fromTable][] = $this->lastInserted;
        }
        return 1;
    }

    public function orderBy($sort, $order = null) { return $this; }
    public function setMaxResults($maxResults) { return $this; }
    public function set($key, $value) { return $this; }

    // Dummy implementations of remaining IQueryBuilder methods
    public function automaticTablePrefix($enabled) {}
    public function func() {}
    public function getType() {}
    public function getConnection() {}
    public function getState() {}
    public function getSQL() {}
    public function setParameter($key, $value, $type = null) {}
    public function setParameters(array $params, array $types = []) {}
    public function getParameters() {}
    public function getParameter($key) {}
    public function getParameterTypes() {}
    public function getParameterType($key) {}
    public function setFirstResult($firstResult) {}
    public function getFirstResult() {}
    public function getMaxResults() {}
    public function selectAlias($select, $alias): self { return $this; }
    public function selectDistinct($select) {}
    public function addSelect(...$select) {}
    public function delete($delete = null, $alias = null) {}
    public function update($update = null, $alias = null) {}
    public function join($fromAlias, $join, $alias, $condition = null) {}
    public function innerJoin($fromAlias, $join, $alias, $condition = null) {}
    public function leftJoin($fromAlias, $join, $alias, $condition = null) {}
    public function rightJoin($fromAlias, $join, $alias, $condition = null) {}
    public function orWhere(...$where) {}
    public function groupBy(...$groupBys) {}
    public function addGroupBy(...$groupBy) {}
    public function setValue($column, $value) {}
    public function having(...$having) {}
    public function andHaving(...$having) {}
    public function orHaving(...$having) {}
    public function addOrderBy($sort, $order = null) {}
    public function getQueryPart($queryPartName) {}
    public function getQueryParts() {}
    public function resetQueryParts($queryPartNames = null) {}
    public function resetQueryPart($queryPartName) {}
    public function createPositionalParameter($value, $type = self::PARAM_STR) {}
    public function createParameter($name) {}
    public function createFunction($call) {}
    public function getLastInsertId(): int { return 1; }
    public function getTableName($table) {}
    public function prefixTableName(string $table): string { return $table; }
    public function getColumnName($column, $tableAlias = '') {}
    public function hintShardKey(string $column, mixed $value, bool $overwrite = false): self { return $this; }
    public function runAcrossAllShards(): self { return $this; }
    public function getOutputColumns(): array { return []; }
    public function forUpdate(ConflictResolutionMode $conflictResolutionMode = ConflictResolutionMode::Ordinary): self { return $this; }
}

class StubDBConnection extends \OC\DB\ConnectionAdapter {
    public array $tables = [
        'groups' => [],
        'archive_ai_outbox' => [],
        'archive_file_grants' => [],
    ];

    public ?StubQueryBuilder $lastQb = null;

    public function __construct() {
    }

    public function getQueryBuilder(): IQueryBuilder {
        $qb = new StubQueryBuilder($this->tables);
        $this->lastQb = $qb;
        return $qb;
    }
}

class StubLogger implements LoggerInterface {
    public array $logs = [];
    public function emergency($message, array $context = []): void { $this->logs[] = ['emergency', $message]; }
    public function alert($message, array $context = []): void { $this->logs[] = ['alert', $message]; }
    public function critical($message, array $context = []): void { $this->logs[] = ['critical', $message]; }
    public function error($message, array $context = []): void { $this->logs[] = ['error', $message]; }
    public function warning($message, array $context = []): void { $this->logs[] = ['warning', $message]; }
    public function notice($message, array $context = []): void { $this->logs[] = ['notice', $message]; }
    public function info($message, array $context = []): void { $this->logs[] = ['info', $message]; }
    public function debug($message, array $context = []): void { $this->logs[] = ['debug', $message]; }
    public function log($level, $message, array $context = []): void { $this->logs[] = [$level, $message]; }
}

// =========================================================================
// Test Execution Engine
// =========================================================================

class AuthoritativeGroupResolutionTestRunner {
    private int $passes = 0;
    private int $failures = 0;

    private function assert(bool $condition, string $testName, string $failureDetails = ''): void {
        if ($condition) {
            $this->passes++;
            echo "  [PASS] {$testName}\n";
        } else {
            $this->failures++;
            echo "  [FAIL] {$testName}: {$failureDetails}\n";
        }
    }

    public function runAll(): bool {
        echo "======================================================================\n";
        echo "Running Enterprise Archive Authoritative Group Resolution Tests (PHP)\n";
        echo "======================================================================\n";

        $this->test1_exact_one_authoritative_group_by_gid();
        $this->test2_exact_one_authoritative_group_by_display_name_in_groups_table();
        $this->test3_path_segment_guess_prevented_outside_enterprise_archive();
        $this->test4_owner_group_membership_alone_cannot_override_file_location();
        $this->test5_group_grant_alone_cannot_override_file_group();
        $this->test6_file_directly_in_enterprise_archive_root();
        $this->test7_unregistered_department_folder_in_archive();
        $this->test8_ambiguous_department_display_name();
        $this->test9_dependency_injection_failure_throws_type_error();
        $this->test10_record_file_event_records_authoritative_group_and_unknown();

        echo "----------------------------------------------------------------------\n";
        echo "Results: {$this->passes} passed, {$this->failures} failed.\n";
        echo "======================================================================\n";

        return $this->failures === 0;
    }

    /**
     * 1. A file with exactly one authoritative group (by GID) maps to that group's Workspace name.
     */
    private function test1_exact_one_authoritative_group_by_gid(): void {
        $db = new StubDBConnection();
        $gm = new StubGroupManager();
        $logger = new StubLogger();
        $gm->addGroup('Finance', 'Finance Department');

        $service = new AiOutboxService($db, $logger, $gm);
        $file = new StubFile(101, 'files/Enterprise_Archive/Finance/2026/quarterly_audit.txt', 'quarterly_audit.txt');

        $resolved = $service->resolveFileGroup($file, 'admin');
        $this->assert($resolved === 'Finance', '1. File in Enterprise_Archive/<GID> resolves to authoritative group canonical GID', "Got: " . var_export($resolved, true));
    }

    /**
     * 2. A file in Enterprise_Archive matching group display name in oc_groups resolves correctly.
     */
    private function test2_exact_one_authoritative_group_by_display_name_in_groups_table(): void {
        $db = new StubDBConnection();
        $gm = new StubGroupManager();
        $logger = new StubLogger();
        $gm->addGroup('hr_dept', 'Human Resources');
        $db->tables['groups'] = [
            ['gid' => 'hr_dept', 'displayname' => 'Human Resources']
        ];

        $service = new AiOutboxService($db, $logger, $gm);
        $file = new StubFile(102, 'Enterprise_Archive/Human Resources/hiring_plan.md', 'hiring_plan.md');

        $resolved = $service->resolveFileGroup($file, 'admin');
        $this->assert($resolved === 'hr_dept', '2. File matching display name in groups table resolves to canonical GID', "Got: " . var_export($resolved, true));
    }

    /**
     * 3. Unknown or unresolved group never falls back to a path-segment guess.
     */
    private function test3_path_segment_guess_prevented_outside_enterprise_archive(): void {
        $db = new StubDBConnection();
        $gm = new StubGroupManager();
        $logger = new StubLogger();
        $gm->addGroup('Finance', 'Finance');

        $service = new AiOutboxService($db, $logger, $gm);
        // Path has 'Finance' as a folder segment, but it's in a personal directory, NOT Enterprise_Archive!
        $file = new StubFile(103, 'personal_folders/Finance/secret_notes.txt', 'secret_notes.txt');

        $resolved = $service->resolveFileGroup($file, 'user1');
        $this->assert($resolved === null, '3. File outside Enterprise_Archive NEVER infers group from matching path segment', "Expected null, got: " . var_export($resolved, true));
    }

    /**
     * 4. Owner group membership alone cannot override file location.
     */
    private function test4_owner_group_membership_alone_cannot_override_file_location(): void {
        $db = new StubDBConnection();
        $gm = new StubGroupManager();
        $logger = new StubLogger();
        $gm->addGroup('Legal', 'Legal');

        $service = new AiOutboxService($db, $logger, $gm);
        // Owner belongs to Legal, but file is in a generic personal path
        $file = new StubFile(104, 'files/notes.txt', 'notes.txt');

        $resolved = $service->resolveFileGroup($file, 'legal_user');
        $this->assert($resolved === null, '4. Uploader group membership alone NEVER substitutes for authoritative file location', "Expected null, got: " . var_export($resolved, true));
    }

    /**
     * 5. Explicit group grant alone cannot override or produce group assignment.
     */
    private function test5_group_grant_alone_cannot_override_file_group(): void {
        $db = new StubDBConnection();
        $gm = new StubGroupManager();
        $logger = new StubLogger();
        $gm->addGroup('Compliance_Unit', 'Compliance Unit');
        // Simulate grant in archive_file_grants
        $db->tables['archive_file_grants'] = [
            ['file_id' => 105, 'grantee_type' => 'group', 'grantee_id' => 'Compliance_Unit', 'permissions' => 31]
        ];

        $service = new AiOutboxService($db, $logger, $gm);
        $file = new StubFile(105, 'personal_docs/contract.txt', 'contract.txt');

        $resolved = $service->resolveFileGroup($file, 'admin');
        $this->assert($resolved === null, '5. Group access grant alone NEVER treats grantee as destination group', "Expected null, got: " . var_export($resolved, true));
    }

    /**
     * 6. File directly in root Enterprise_Archive/ has no department group.
     */
    private function test6_file_directly_in_enterprise_archive_root(): void {
        $db = new StubDBConnection();
        $gm = new StubGroupManager();
        $logger = new StubLogger();
        $gm->addGroup('Finance', 'Finance');

        $service = new AiOutboxService($db, $logger, $gm);
        // File uploaded directly under Enterprise_Archive/ without a department folder
        $file = new StubFile(106, 'Enterprise_Archive/root_memo.txt', 'root_memo.txt');

        $resolved = $service->resolveFileGroup($file, 'admin');
        $this->assert($resolved === null, '6. File directly in root of Enterprise_Archive/ returns null (unresolved)', "Expected null, got: " . var_export($resolved, true));
    }

    /**
     * 7. Department folder not registered in IGroupManager or groups table returns null.
     */
    private function test7_unregistered_department_folder_in_archive(): void {
        $db = new StubDBConnection();
        $gm = new StubGroupManager();
        $logger = new StubLogger();

        $service = new AiOutboxService($db, $logger, $gm);
        // Folder 'Unknown_Dept' does NOT exist in group authority
        $file = new StubFile(107, 'Enterprise_Archive/Unknown_Dept/doc.txt', 'doc.txt');

        $resolved = $service->resolveFileGroup($file, 'admin');
        $this->assert($resolved === null, '7. Unregistered department folder in Enterprise_Archive returns null', "Expected null, got: " . var_export($resolved, true));
    }

    /**
     * 8. Ambiguous department display name matching multiple groups returns null.
     */
    private function test8_ambiguous_department_display_name(): void {
        $db = new StubDBConnection();
        $gm = new StubGroupManager();
        $logger = new StubLogger();
        $gm->addGroup('group_a', 'Security Team');
        $gm->addGroup('group_b', 'Security Team');
        $db->tables['groups'] = [
            ['gid' => 'group_a', 'displayname' => 'Security Team'],
            ['gid' => 'group_b', 'displayname' => 'Security Team'],
        ];

        $service = new AiOutboxService($db, $logger, $gm);
        $file = new StubFile(108, 'Enterprise_Archive/Security Team/incident.txt', 'incident.txt');

        $resolved = $service->resolveFileGroup($file, 'admin');
        $this->assert($resolved === null, '8. Ambiguous department folder matching multiple groups returns null', "Expected null, got: " . var_export($resolved, true));
    }

    /**
     * 9. Dependency injection failure throws TypeError (cannot silently disable resolution).
     */
    private function test9_dependency_injection_failure_throws_type_error(): void {
        $db = new StubDBConnection();
        $logger = new StubLogger();

        $threw = false;
        try {
            // Passing null for mandatory IGroupManager must fail immediately with TypeError
            /** @phpstan-ignore-next-line */
            new AiOutboxService($db, $logger, null);
        } catch (\TypeError $e) {
            $threw = true;
        }

        $this->assert($threw, '9. Missing IGroupManager dependency throws TypeError (fail-closed, no silent fallback)');
    }

    /**
     * 10. recordFileEvent() emits authoritative group name for valid files and 'UNKNOWN' for unresolved files.
     */
    private function test10_record_file_event_records_authoritative_group_and_unknown(): void {
        $db = new StubDBConnection();
        $gm = new StubGroupManager();
        $logger = new StubLogger();
        $gm->addGroup('Finance', 'Finance Department');

        $service = new AiOutboxService($db, $logger, $gm);

        // 10a. Valid file in department folder
        $validFile = new StubFile(201, 'Enterprise_Archive/Finance/report.csv', 'report.csv');
        $evt1 = $service->recordFileEvent($validFile, 'user_fin');
        $this->assert($evt1 !== null, '10a. recordFileEvent returns event ID for qualified file');
        $recordedGroup1 = $db->tables['archive_ai_outbox'][0]['group_name'] ?? '';
        $this->assert($recordedGroup1 === 'Finance', '10a. recordFileEvent stores authoritative group GID', "Got: {$recordedGroup1}");

        // 10b. Unresolved file (outside Enterprise_Archive)
        $unresolvedFile = new StubFile(202, 'personal/report.csv', 'report.csv');
        $evt2 = $service->recordFileEvent($unresolvedFile, 'user_fin');
        $this->assert($evt2 !== null, '10b. recordFileEvent returns event ID for unresolved file');
        $recordedGroup2 = $db->tables['archive_ai_outbox'][1]['group_name'] ?? '';
        $this->assert($recordedGroup2 === 'UNKNOWN', '10b. recordFileEvent stores explicit UNKNOWN for unresolved file', "Got: {$recordedGroup2}");
    }
}

// Execute tests
$runner = new AuthoritativeGroupResolutionTestRunner();
$success = $runner->runAll();
exit($success ? 0 : 1);
