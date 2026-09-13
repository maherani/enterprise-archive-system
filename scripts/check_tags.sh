#!/bin/bash
docker exec archive_db psql -U nextcloud_user -d nextcloud -c "
SELECT 
    t.id AS tag_id,
    t.name AS tag_name,
    COUNT(m.objectid) AS total_mappings,
    COUNT(CASE WHEN f.fileid IS NOT NULL AND f.path NOT LIKE 'files_trashbin%' THEN 1 END) AS active_mappings,
    COUNT(CASE WHEN f.fileid IS NOT NULL AND f.path LIKE 'files_trashbin%' THEN 1 END) AS trash_mappings,
    COUNT(CASE WHEN f.fileid IS NULL AND m.objectid IS NOT NULL THEN 1 END) AS deleted_mappings
FROM oc_systemtag t
LEFT JOIN oc_systemtag_object_mapping m ON t.id = m.systemtagid AND m.objecttype = 'files'
LEFT JOIN oc_filecache f ON m.objectid::bigint = f.fileid
GROUP BY t.id, t.name
ORDER BY t.id;
"
