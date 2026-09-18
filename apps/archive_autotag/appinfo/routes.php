<?php
declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'Page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'TagFilter#listVisibleTags', 'url' => '/api/tags', 'verb' => 'GET'],
        ['name' => 'TagFilter#filterByTags', 'url' => '/api/filter', 'verb' => 'GET'],
        ['name' => 'TagFilter#listAllArchiveFiles', 'url' => '/api/files', 'verb' => 'GET'],
        ['name' => 'FolderRequest#getUserRole', 'url' => '/api/user-role', 'verb' => 'GET'],
        ['name' => 'FolderRequest#getGroupFolders', 'url' => '/api/group-folders', 'verb' => 'GET'],
        ['name' => 'FolderRequest#getAllFolders', 'url' => '/api/folders', 'verb' => 'GET'],
        ['name' => 'FolderRequest#createDirect', 'url' => '/api/folders/create', 'verb' => 'POST'],

        ['name' => 'FolderRequest#index', 'url' => '/api/folder-requests', 'verb' => 'GET'],
        ['name' => 'FolderRequest#create', 'url' => '/api/folder-requests', 'verb' => 'POST'],
        ['name' => 'FolderRequest#show', 'url' => '/api/folder-requests/{id}', 'verb' => 'GET'],
        ['name' => 'FolderRequest#auditTrail', 'url' => '/api/folder-requests/{id}/audit', 'verb' => 'GET'],
        ['name' => 'FolderRequest#approve', 'url' => '/api/folder-requests/{id}/approve', 'verb' => 'POST'],
        ['name' => 'FolderRequest#reject', 'url' => '/api/folder-requests/{id}/reject', 'verb' => 'POST'],

        // Secure AI File Retrieval API & Swagger UI
        ['name' => 'AiFile#getFile', 'url' => '/api/v1/ai/files/{fileId}', 'verb' => 'GET'],
        ['name' => 'AiFile#getMetadata', 'url' => '/api/v1/ai/files/{fileId}/metadata', 'verb' => 'GET'],
        ['name' => 'AiFile#openapiSpec', 'url' => '/api/openapi.json', 'verb' => 'GET'],
        ['name' => 'AiFile#swaggerUi', 'url' => '/api/docs', 'verb' => 'GET'],
    ],
    'ocs' => [
        ['name' => 'TagFilter#listVisibleTags', 'url' => '/api/tags', 'verb' => 'GET'],
        ['name' => 'TagFilter#filterByTags', 'url' => '/api/filter', 'verb' => 'GET'],
        ['name' => 'TagFilter#listAllArchiveFiles', 'url' => '/api/files', 'verb' => 'GET'],
        ['name' => 'FolderRequest#getUserRole', 'url' => '/api/user-role', 'verb' => 'GET'],
        ['name' => 'FolderRequest#getGroupFolders', 'url' => '/api/group-folders', 'verb' => 'GET'],
        ['name' => 'FolderRequest#getAllFolders', 'url' => '/api/folders', 'verb' => 'GET'],
        ['name' => 'FolderRequest#createDirect', 'url' => '/api/folders/create', 'verb' => 'POST'],

        ['name' => 'FolderRequest#index', 'url' => '/api/folder-requests', 'verb' => 'GET'],
        ['name' => 'FolderRequest#create', 'url' => '/api/folder-requests', 'verb' => 'POST'],
        ['name' => 'FolderRequest#show', 'url' => '/api/folder-requests/{id}', 'verb' => 'GET'],
        ['name' => 'FolderRequest#auditTrail', 'url' => '/api/folder-requests/{id}/audit', 'verb' => 'GET'],
        ['name' => 'FolderRequest#approve', 'url' => '/api/folder-requests/{id}/approve', 'verb' => 'POST'],
        ['name' => 'FolderRequest#reject', 'url' => '/api/folder-requests/{id}/reject', 'verb' => 'POST'],

        // Secure AI File Retrieval API & Swagger UI
        ['name' => 'AiFile#getFile', 'url' => '/api/v1/ai/files/{fileId}', 'verb' => 'GET'],
        ['name' => 'AiFile#getMetadata', 'url' => '/api/v1/ai/files/{fileId}/metadata', 'verb' => 'GET'],
        ['name' => 'AiFile#openapiSpec', 'url' => '/api/openapi.json', 'verb' => 'GET'],
        ['name' => 'AiFile#swaggerUi', 'url' => '/api/docs', 'verb' => 'GET'],
    ],
];
