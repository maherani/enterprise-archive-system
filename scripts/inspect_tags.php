<?php
require_once '/var/www/html/lib/base.php';
$conn = \OC::$server->getDatabaseConnection();

echo "=== SYSTEM TAGS ===\n";
$tags = $conn->executeQuery("SELECT id, name, visibility, editable FROM oc_systemtag ORDER BY id")->fetchAllAssociative();
foreach ($tags as $t) {
    echo "Tag #{$t['id']}: '{$t['name']}' (vis: {$t['visibility']}, edit: {$t['editable']})\n";
}

echo "\n=== ACTIVE DIRECTORIES IN ENTERPRISE_ARCHIVE ===\n";
$folders = $conn->executeQuery("
    SELECT fileid, path FROM oc_filecache
    WHERE mimetype = 2 AND (path LIKE '%Enterprise_Archive%' OR path LIKE 'files/%') AND path NOT LIKE '%files_trashbin%'
    ORDER BY path
")->fetchAllAssociative();
foreach ($folders as $f) {
    echo "Folder #{$f['fileid']}: {$f['path']}\n";
}

echo "\n=== SUMMARY OF TAG USAGE ON ACTIVE FILES ===\n";
$usage = $conn->executeQuery("
    SELECT t.id, t.name, count(m.objectid) as active_count
    FROM oc_systemtag t
    LEFT JOIN oc_systemtag_object_mapping m ON t.id = m.systemtagid
    LEFT JOIN oc_filecache f ON m.objectid::bigint = f.fileid
    WHERE (f.path IS NOT NULL AND f.path NOT LIKE '%files_trashbin%')
    GROUP BY t.id, t.name
    ORDER BY t.id
")->fetchAllAssociative();
foreach ($usage as $u) {
    echo "Tag #{$u['id']} '{$u['name']}': {$u['active_count']} active objects\n";
}
