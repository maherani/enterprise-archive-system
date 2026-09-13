<?php
require_once '/var/www/html/lib/base.php';

$container = \OC::$server;
$db = $container->getDatabaseConnection();
$tagManager = $container->get(\OCP\SystemTag\ISystemTagManager::class);
$userManager = $container->get(\OCP\IUserManager::class);

echo "=== SIMULATING TAG RECONCILIATION ===\n";

// 1. Check stale mappings
$staleTrash = $db->executeQuery("
    SELECT count(m.objectid) as cnt
    FROM oc_systemtag_object_mapping m
    JOIN oc_filecache f ON m.objectid = f.fileid::text
    WHERE m.objecttype = 'files' AND f.path LIKE 'files_trashbin%'
")->fetchOne();

$staleDeleted = $db->executeQuery("
    SELECT count(m.objectid) as cnt
    FROM oc_systemtag_object_mapping m
    LEFT JOIN oc_filecache f ON m.objectid = f.fileid::text
    WHERE m.objecttype = 'files' AND f.fileid IS NULL
")->fetchOne();

echo "Stale mappings found: Trashbin = {$staleTrash}, Deleted files = {$staleDeleted}\n";

// 2. Collect active folder segments
$folderRows = $db->executeQuery("
    SELECT path, name FROM oc_filecache
    WHERE mimetype = 2 
      AND path NOT LIKE 'files_trashbin%'
      AND path NOT LIKE 'cache%'
      AND path NOT LIKE 'appdata_%'
      AND path NOT LIKE 'files_versions%'
      AND path != 'files'
      AND path != ''
")->fetchAllAssociative();

$activeFolderNames = [];
foreach ($folderRows as $row) {
    $path = trim($row['path'], '/');
    $parts = explode('/', $path);
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '' || $part === 'files' || $userManager->userExists($part)) {
            continue;
        }
        $activeFolderNames[mb_strtolower($part)] = $part;
    }
}
echo "Active folder names detected: " . implode(', ', $activeFolderNames) . "\n\n";

// 3. Evaluate tags
$allTags = $tagManager->getAllTags();
echo "Evaluating " . count($allTags) . " tags:\n";
foreach ($allTags as $tag) {
    $tid = $tag->getId();
    $name = $tag->getName();
    $lower = mb_strtolower($name);
    
    $isFolder = isset($activeFolderNames[$lower]);
    
    // Count active file mappings
    $activeCount = (int)$db->executeQuery("
        SELECT count(m.objectid)
        FROM oc_systemtag_object_mapping m
        JOIN oc_filecache f ON m.objectid = f.fileid::text
        WHERE m.systemtagid = ? AND m.objecttype = 'files'
          AND f.path NOT LIKE 'files_trashbin%'
    ", [$tid])->fetchOne();
    
    $decision = 'UNKNOWN';
    if ($isFolder) {
        $decision = "KEEP (Matches active folder '{$activeFolderNames[$lower]}', active files: {$activeCount})";
    } elseif ($activeCount > 0) {
        $decision = "KEEP (Assigned to {$activeCount} active files)";
    } else {
        $decision = "DELETE SURPLUS (0 active folders, 0 active files)";
    }
    
    echo "  - Tag #{$tid} '{$name}': {$decision}\n";
}
