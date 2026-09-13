#!/bin/bash
docker exec archive_db psql -U nextcloud_user -d nextcloud -c "
SELECT fileid, storage, path, name
FROM oc_filecache
WHERE mimetype = 2 
  AND path NOT LIKE 'files_trashbin%'
  AND path NOT LIKE 'cache%'
  AND path NOT LIKE 'appdata_%'
  AND path != 'files'
  AND path != ''
ORDER BY path;
"
