<?php
declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'Page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'TagFilter#listVisibleTags', 'url' => '/api/tags', 'verb' => 'GET'],
        ['name' => 'TagFilter#filterByTags', 'url' => '/api/filter', 'verb' => 'GET'],
        ['name' => 'TagFilter#listAllArchiveFiles', 'url' => '/api/files', 'verb' => 'GET'],
        ['name' => 'TagFilter#listFolderFiles', 'url' => '/api/folder-files', 'verb' => 'GET'],
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

        // AI Security Admin & Interactive Testing Console
        ['name' => 'AiAdmin#overview', 'url' => '/api/ai/admin/overview', 'verb' => 'GET'],
        ['name' => 'AiAdmin#createService', 'url' => '/api/ai/admin/services/create', 'verb' => 'POST'],
        ['name' => 'AiAdmin#createToken', 'url' => '/api/ai/admin/tokens/create', 'verb' => 'POST'],
        ['name' => 'AiAdmin#rotateToken', 'url' => '/api/ai/admin/tokens/rotate', 'verb' => 'POST'],
        ['name' => 'AiAdmin#revokeToken', 'url' => '/api/ai/admin/tokens/revoke', 'verb' => 'POST'],
        ['name' => 'AiAdmin#addDelegation', 'url' => '/api/ai/admin/delegations/add', 'verb' => 'POST'],
        ['name' => 'AiAdmin#removeDelegation', 'url' => '/api/ai/admin/delegations/remove', 'verb' => 'POST'],
        ['name' => 'AiAdmin#audit', 'url' => '/api/ai/admin/audit', 'verb' => 'GET'],
        ['name' => 'AiAdmin#testApi', 'url' => '/api/ai/admin/test-api', 'verb' => 'POST'],
        ['name' => 'AiAdmin#inspectPermission', 'url' => '/api/permission/inspect', 'verb' => 'GET'],

        // Global Access-Aware Navigation & Current Path
        ['name' => 'Navigation#getResources', 'url' => '/api/nav/resources', 'verb' => 'GET'],
        // Group Admin Tag Governance
        ['name' => 'GroupTag#listTags', 'url' => '/api/group-tags', 'verb' => 'GET'],
        ['name' => 'GroupTag#createTag', 'url' => '/api/group-tags/create', 'verb' => 'POST'],
        ['name' => 'GroupTag#deleteTag', 'url' => '/api/group-tags/delete', 'verb' => 'POST'],
        ['name' => 'GroupTag#assignTag', 'url' => '/api/group-tags/assign', 'verb' => 'POST'],
        ['name' => 'GroupTag#removeTag', 'url' => '/api/group-tags/remove', 'verb' => 'POST'],
        ['name' => 'GroupTag#reconcileTags', 'url' => '/api/group-tags/reconcile', 'verb' => 'POST'],
    ],
    'ocs' => [
        ['name' => 'TagFilter#listVisibleTags', 'url' => '/api/tags', 'verb' => 'GET'],
        ['name' => 'TagFilter#filterByTags', 'url' => '/api/filter', 'verb' => 'GET'],
        ['name' => 'TagFilter#listAllArchiveFiles', 'url' => '/api/files', 'verb' => 'GET'],
        ['name' => 'TagFilter#listFolderFiles', 'url' => '/api/folder-files', 'verb' => 'GET'],
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

        // AI Security Admin & Interactive Testing Console
        ['name' => 'AiAdmin#overview', 'url' => '/api/ai/admin/overview', 'verb' => 'GET'],
        ['name' => 'AiAdmin#createService', 'url' => '/api/ai/admin/services/create', 'verb' => 'POST'],
        ['name' => 'AiAdmin#createToken', 'url' => '/api/ai/admin/tokens/create', 'verb' => 'POST'],
        ['name' => 'AiAdmin#rotateToken', 'url' => '/api/ai/admin/tokens/rotate', 'verb' => 'POST'],
        ['name' => 'AiAdmin#revokeToken', 'url' => '/api/ai/admin/tokens/revoke', 'verb' => 'POST'],
        ['name' => 'AiAdmin#addDelegation', 'url' => '/api/ai/admin/delegations/add', 'verb' => 'POST'],
        ['name' => 'AiAdmin#removeDelegation', 'url' => '/api/ai/admin/delegations/remove', 'verb' => 'POST'],
        ['name' => 'AiAdmin#audit', 'url' => '/api/ai/admin/audit', 'verb' => 'GET'],
        ['name' => 'AiAdmin#testApi', 'url' => '/api/ai/admin/test-api', 'verb' => 'POST'],
        ['name' => 'AiAdmin#inspectPermission', 'url' => '/api/permission/inspect', 'verb' => 'GET'],

        // Global Access-Aware Navigation & Current Path
        ['name' => 'Navigation#getResources', 'url' => '/api/nav/resources', 'verb' => 'GET'],
        // Group Admin Tag Governance
        ['name' => 'GroupTag#listTags', 'url' => '/api/group-tags', 'verb' => 'GET'],
        ['name' => 'GroupTag#createTag', 'url' => '/api/group-tags/create', 'verb' => 'POST'],
        ['name' => 'GroupTag#deleteTag', 'url' => '/api/group-tags/delete', 'verb' => 'POST'],
        ['name' => 'GroupTag#assignTag', 'url' => '/api/group-tags/assign', 'verb' => 'POST'],
        ['name' => 'GroupTag#removeTag', 'url' => '/api/group-tags/remove', 'verb' => 'POST'],
        ['name' => 'GroupTag#reconcileTags', 'url' => '/api/group-tags/reconcile', 'verb' => 'POST'],
    ],
];
