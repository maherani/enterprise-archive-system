<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Security\Permission;

interface IPermissionResolver {
    /**
     * Fast-path boolean check for a specific operation on a file
     */
    public function can(string $userId, int $fileId, int $operation = PermissionOperation::READ): bool;

    /**
     * Evaluate detailed permission decision on a file
     */
    public function evaluateFile(string $userId, int $fileId, int $operation = PermissionOperation::READ): PermissionDecision;

    /**
     * Evaluate permission decision on a folder path (relative or absolute in archive)
     */
    public function evaluateFolder(string $userId, string $folderPath, int $operation = PermissionOperation::READ): PermissionDecision;

    /**
     * Evaluate tag visibility or management permission
     */
    public function evaluateTag(string $userId, int $tagId, int $operation = PermissionOperation::READ_METADATA): PermissionDecision;

    /**
     * Filter a list of file IDs returning only accessible ones for the requested operation
     * @param int[] $fileIds
     * @return int[]
     */
    public function filterAccessibleFileIds(string $userId, array $fileIds, int $operation = PermissionOperation::READ): array;

    /**
     * Filter top-level/sub-level folder nodes returning only accessible ones
     * @param array $folders Array of Folder objects or assoc arrays with ['name', 'path']
     * @return array
     */
    public function filterAccessibleFolders(string $userId, array $folders): array;

    /**
     * Filter tag IDs returning only visible ones
     * @param int[] $tagIds
     * @return int[]
     */
    public function filterAccessibleTagIds(string $userId, array $tagIds): array;
}
