<?php
declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'Page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'TagFilter#listVisibleTags', 'url' => '/api/tags', 'verb' => 'GET'],
        ['name' => 'TagFilter#filterByTags', 'url' => '/api/filter', 'verb' => 'GET'],
        ['name' => 'TagFilter#listAllArchiveFiles', 'url' => '/api/files', 'verb' => 'GET'],
    ],
    'ocs' => [
        ['name' => 'TagFilter#listVisibleTags', 'url' => '/api/tags', 'verb' => 'GET'],
        ['name' => 'TagFilter#filterByTags', 'url' => '/api/filter', 'verb' => 'GET'],
        ['name' => 'TagFilter#listAllArchiveFiles', 'url' => '/api/files', 'verb' => 'GET'],
    ],
];
