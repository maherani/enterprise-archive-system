<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Controller;

use OCA\ArchiveAutoTag\AppInfo\Application;
use OCA\ArchiveAutoTag\Service\AiFileService;
use OCP\AppFramework\Controller;
use OCA\ArchiveAutoTag\Exception\AuditRequiredException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCA\ArchiveAutoTag\Http\AuditedStreamResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\File;
use OCP\IRequest;
use Symfony\Component\HttpFoundation\HeaderUtils;

class AiFileController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private AiFileService $aiFileService,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Securely stream an archive file to an authorized AI client or Swagger UI.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function getFile(int $fileId): Http\Response {
        $requestId = (string)($this->request->getHeader('X-Request-ID') ?: ('req_ai_' . bin2hex(random_bytes(8))));
        $correlationId = (string)($this->request->getHeader('X-Correlation-ID') ?: $requestId);
        $clientIp = (string)$this->request->getRemoteAddress();

        // 1. Authentication Check
        $auth = $this->aiFileService->authenticateRequest($this->request);
        if (!$auth['authenticated']) {
            $statusCode = (int)($auth['status_code'] ?? Http::STATUS_UNAUTHORIZED);
            $resultStatus = ($statusCode === Http::STATUS_FORBIDDEN) ? 'FORBIDDEN' : 'UNAUTHORIZED';

            $this->aiFileService->recordAudit(
                $requestId,
                $auth['actor_uid'] ?: 'anonymous',
                $auth['client_id'] ?: 'unknown',
                $fileId,
                '',
                $auth['auth_type'],
                $resultStatus,
                $clientIp,
                0,
                $auth['error'],
                $auth['service_id'] ?? 'unknown',
                $auth['token_id'] ?? null,
                $auth['delegation_requested'] ?? null,
                $auth['delegation_status'] ?? 'NONE',
                $correlationId,
                0,
                'NONE',
                'AUTH'
            );

            $headers = [
                'X-Request-ID' => $requestId,
                'X-Correlation-ID' => $correlationId,
            ];
            if ($statusCode === Http::STATUS_UNAUTHORIZED) {
                $headers['WWW-Authenticate'] = 'Basic realm="Enterprise Archive AI API", Bearer realm="Enterprise Archive AI API"';
            }

            return new JSONResponse([
                'status' => 'error',
                'message' => $auth['error'],
                'code' => $statusCode,
                'request_id' => $requestId,
                'correlation_id' => $correlationId,
            ], $statusCode, $headers);
        }

        $actorUid = (string)$auth['actor_uid'];
        $clientId = (string)$auth['client_id'];
        $authType = (string)$auth['auth_type'];
        $serviceId = (string)($auth['service_id'] ?? 'unknown');
        $tokenId = $auth['token_id'] ?? null;
        $delegationRequested = $auth['delegation_requested'] ?? null;
        $delegationStatus = (string)($auth['delegation_status'] ?? 'NONE');

        // 2. Authorization & File Existence Check
        $val = $this->aiFileService->validateAndGetFileNode($fileId, $actorUid);
        if (!$val['allowed']) {
            $resultCode = $val['error_code'];
            $resultStatus = $resultCode === 403 ? 'FORBIDDEN' : ($resultCode === 404 ? 'NOT_FOUND' : 'INVALID_REQUEST');

            $this->aiFileService->recordAudit(
                $requestId,
                $actorUid,
                $clientId,
                $fileId,
                '',
                $authType,
                $resultStatus,
                $clientIp,
                0,
                $val['error_message'],
                $serviceId,
                $tokenId,
                $delegationRequested,
                $delegationStatus,
                $correlationId,
                0,
                'NONE',
                'ACCESS_CHECK'
            );

            return new JSONResponse([
                'status' => 'error',
                'message' => $val['error_message'],
                'code' => $resultCode,
                'request_id' => $requestId,
                'correlation_id' => $correlationId,
            ], $resultCode, [
                'X-Request-ID' => $requestId,
                'X-Correlation-ID' => $correlationId,
            ]);
        }

        /** @var \OCP\Files\Node $node */
        $node = $val['node'];
        $fileName = $node->getName();
        $fileSize = $node->getSize();
        $mimetype = $node->getMimetype();

        // 3. Record Initial Audit (Audit-Required: fail-closed before opening file stream)
        // Stage=AUTHORIZED, Transfer=PENDING, bytes_requested=$fileSize, bytes_served=0
        try {
            $this->aiFileService->recordAudit(
                $requestId,
                $actorUid,
                $clientId,
                $fileId,
                $fileName,
                $authType,
                'ALLOWED',
                $clientIp,
                0,
                null,
                $serviceId,
                $tokenId,
                $delegationRequested,
                $delegationStatus,
                $correlationId,
                $fileSize,
                'PENDING',
                'AUTHORIZED'
            );
        } catch (AuditRequiredException $auditEx) {
            return new JSONResponse([
                'status' => 'error',
                'message' => 'Audit subsystem failure: file retrieval denied for security compliance',
                'code' => Http::STATUS_SERVICE_UNAVAILABLE,
                'request_id' => $requestId,
                'correlation_id' => $correlationId,
            ], Http::STATUS_SERVICE_UNAVAILABLE, [
                'X-Request-ID' => $requestId,
                'X-Correlation-ID' => $correlationId,
            ]);
        }

        // 4. Open file stream from archive storage
        $simFopenFail = $this->request->getHeader('X-Simulate-Fopen-Failure') === 'true';
        $stream = $simFopenFail ? false : ($node instanceof File ? $node->fopen('rb') : fopen($node->getPath(), 'rb'));
        if ($stream === false) {
            $this->aiFileService->updateTransferProgress(
                $requestId,
                0,
                'STREAM_FAILED',
                'FAILED',
                0,
                'Failed to open file stream from archive storage'
            );
            return new JSONResponse([
                'status' => 'error',
                'message' => 'Failed to open file stream from archive storage',
                'code' => Http::STATUS_INTERNAL_SERVER_ERROR,
                'request_id' => $requestId,
                'correlation_id' => $correlationId,
            ], Http::STATUS_INTERNAL_SERVER_ERROR, [
                'X-Request-ID' => $requestId,
                'X-Correlation-ID' => $correlationId,
            ]);
        }

        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $fileName,
            preg_replace('/[^\x20-\x7e]/', '', $fileName) ?: 'archive_file'
        );

        $headers = [
            'Content-Type' => $mimetype,
            'Content-Length' => (string)$fileSize,
            'Content-Disposition' => $disposition,
            'X-Archive-File-ID' => (string)$fileId,
            'X-Archive-File-Name' => rawurlencode($fileName),
            'X-Request-ID' => $requestId,
            'X-Correlation-ID' => $correlationId,
            'X-Actor-UID' => $actorUid,
            'X-Accel-Buffering' => 'no',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];

        return new AuditedStreamResponse($stream, $fileSize, $requestId, $this->aiFileService, Http::STATUS_OK, $headers);
    }

    /**
     * Retrieve controlled metadata for a specific archive file.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function getMetadata(int $fileId): JSONResponse {
        $requestId = (string)($this->request->getHeader('X-Request-ID') ?: ('req_ai_' . bin2hex(random_bytes(8))));
        $auth = $this->aiFileService->authenticateRequest($this->request);
        if (!$auth['authenticated']) {
            $statusCode = (int)($auth['status_code'] ?? Http::STATUS_UNAUTHORIZED);
            return new JSONResponse([
                'status' => 'error',
                'message' => $auth['error'],
                'code' => $statusCode,
                'request_id' => $requestId,
            ], $statusCode);
        }

        $actorUid = (string)$auth['actor_uid'];
        $val = $this->aiFileService->validateAndGetFileNode($fileId, $actorUid);
        if (!$val['allowed']) {
            return new JSONResponse([
                'status' => 'error',
                'message' => $val['error_message'],
                'code' => $val['error_code'],
                'request_id' => $requestId,
            ], $val['error_code']);
        }

        $meta = $this->aiFileService->getFileMetadata($val['node'], $fileId, $actorUid);
        $meta['request_id'] = $requestId;
        return new JSONResponse($meta, Http::STATUS_OK);
    }

    /**
     * Return complete OpenAPI 3.0.3 specification in JSON format.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function openapiSpec(): JSONResponse {
        $spec = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Enterprise Archive AI File Retrieval API',
                'version' => '2.0.9',
                'description' => 'Secure, permission-enforced API providing air-gapped file delivery to internal AI assistants and automated workers without compromising archive ACLs or data boundaries. Hardened with cryptographic token rotation, SHA-256 database hashing, and deny-by-default delegation allowlists.',
                'contact' => [
                    'name' => 'Enterprise Archive Security Team',
                    'url' => 'http://docs.maskan'
                ]
            ],
            'servers' => [
                [
                    'url' => '/index.php/apps/archive_autotag',
                    'description' => 'Local Nextcloud Enterprise Archive'
                ]
            ],
            'components' => [
                'securitySchemes' => [
                    'basicAuth' => [
                        'type' => 'http',
                        'scheme' => 'basic',
                        'description' => 'Nextcloud Username and Password or App Token (User context authentication)'
                    ],
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'API Token',
                        'description' => 'Dedicated AI Service Token for automated daemons and background agents. Validated via SHA-256 hash lookup.'
                    ],
                    'apiKeyAuth' => [
                        'type' => 'apiKey',
                        'in' => 'header',
                        'name' => 'X-API-KEY',
                        'description' => 'Alternative header for AI Service Key'
                    ]
                ],
                'schemas' => [
                    'ErrorResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'status' => ['type' => 'string', 'example' => 'error'],
                            'message' => ['type' => 'string', 'example' => 'Access denied to the requested archive file.'],
                            'code' => ['type' => 'integer', 'example' => 403],
                            'request_id' => ['type' => 'string', 'example' => 'req_ai_9a8b7c6d']
                        ],
                        'required' => ['status', 'message', 'code', 'request_id']
                    ],
                    'FileMetadataResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'status' => ['type' => 'string', 'example' => 'success'],
                            'file' => [
                                'type' => 'object',
                                'properties' => [
                                    'id' => ['type' => 'integer', 'example' => 660],
                                    'name' => ['type' => 'string', 'example' => 'Audit_Report_2026.pdf'],
                                    'size' => ['type' => 'integer', 'example' => 1048576],
                                    'human_size' => ['type' => 'string', 'example' => '1 MB'],
                                    'mimetype' => ['type' => 'string', 'example' => 'application/pdf'],
                                    'mtime' => ['type' => 'integer', 'example' => 1789500000],
                                    'owner' => ['type' => 'string', 'example' => 'archive_user1'],
                                    'tags' => [
                                        'type' => 'array',
                                        'items' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'id' => ['type' => 'integer', 'example' => 12],
                                                'name' => ['type' => 'string', 'example' => 'Compliance_Unit']
                                            ]
                                        ]
                                    ]
                                ]
                            ],
                            'request_id' => ['type' => 'string', 'example' => 'req_ai_123456']
                        ]
                    ]
                ]
            ],
            'security' => [
                ['basicAuth' => []],
                ['bearerAuth' => []],
                ['apiKeyAuth' => []]
            ],
            'paths' => [
                '/api/v1/ai/files/{fileId}' => [
                    'get' => [
                        'summary' => 'Retrieve Archive File (Binary Stream)',
                        'description' => 'Securely streams the target file chunk-by-chunk to authorized AI clients, strictly enforcing organizational ACL and tag isolation. Memory usage is O(1) even for multi-gigabyte files.',
                        'operationId' => 'getArchiveFile',
                        'parameters' => [
                            [
                                'name' => 'fileId',
                                'in' => 'path',
                                'required' => true,
                                'description' => 'Target archive file numeric identifier',
                                'schema' => ['type' => 'integer', 'example' => 660]
                            ],
                            [
                                'name' => 'X-On-Behalf-Of',
                                'in' => 'header',
                                'required' => false,
                                'description' => 'Delegated user UID when calling via AI Service Token. Enforces strict deny-by-default allowlist policy. Delegation to administrative accounts is prohibited.',
                                'schema' => ['type' => 'string', 'example' => 'archive_user1']
                            ],
                            [
                                'name' => 'X-Client-ID',
                                'in' => 'header',
                                'required' => false,
                                'description' => 'Identifier of calling AI agent/subsystem for audit tracking',
                                'schema' => ['type' => 'string', 'example' => 'ollama_rag_agent']
                            ],
                            [
                                'name' => 'X-Request-ID',
                                'in' => 'header',
                                'required' => false,
                                'description' => 'Optional correlation ID for distributed request tracing',
                                'schema' => ['type' => 'string', 'example' => 'req_ai_test_001']
                            ]
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'File successfully authorized and binary stream returned',
                                'headers' => [
                                    'Content-Type' => ['schema' => ['type' => 'string'], 'description' => 'MIME type of file'],
                                    'Content-Length' => ['schema' => ['type' => 'integer'], 'description' => 'Size in bytes'],
                                    'Content-Disposition' => ['schema' => ['type' => 'string'], 'description' => 'Attachment filename header'],
                                    'X-Archive-File-ID' => ['schema' => ['type' => 'string'], 'description' => 'Archive file ID'],
                                    'X-Request-ID' => ['schema' => ['type' => 'string'], 'description' => 'Audit Correlation ID']
                                ],
                                'content' => [
                                    'application/octet-stream' => [
                                        'schema' => [
                                            'type' => 'string',
                                            'format' => 'binary'
                                        ]
                                    ]
                                ]
                            ],
                            '400' => [
                                'description' => 'Invalid file ID or target is a directory',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/ErrorResponse']
                                    ]
                                ]
                            ],
                            '401' => [
                                'description' => 'Authentication missing, invalid credentials, or non-existent delegated identity',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/ErrorResponse']
                                    ]
                                ]
                            ],
                            '403' => [
                                'description' => 'Forbidden - Caller does not have file access, delegation to admin is prohibited, or delegation policy denied',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/ErrorResponse']
                                    ]
                                ]
                            ],
                            '404' => [
                                'description' => 'File not found in archive',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/ErrorResponse']
                                    ]
                                ]
                            ]
                        ]
                    ]
                ],
                '/api/v1/ai/files/{fileId}/metadata' => [
                    'get' => [
                        'summary' => 'Get File Metadata',
                        'description' => 'Returns clean, sanitized metadata for an archive file without exposing server disk paths.',
                        'operationId' => 'getFileMetadata',
                        'parameters' => [
                            [
                                'name' => 'fileId',
                                'in' => 'path',
                                'required' => true,
                                'description' => 'Target archive file numeric identifier',
                                'schema' => ['type' => 'integer', 'example' => 660]
                            ],
                            [
                                'name' => 'X-On-Behalf-Of',
                                'in' => 'header',
                                'required' => false,
                                'description' => 'Delegated user UID when calling via AI Service Token',
                                'schema' => ['type' => 'string', 'example' => 'archive_user1']
                            ]
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'File metadata retrieved successfully',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/FileMetadataResponse']
                                    ]
                                ]
                            ],
                            '401' => [
                                'description' => 'Authentication required',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]]
                            ],
                            '403' => [
                                'description' => 'Access denied',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]]
                            ],
                            '404' => [
                                'description' => 'File not found',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        return new JSONResponse($spec, Http::STATUS_OK);
    }

    /**
     * Render standalone, offline, interactive Swagger UI.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function swaggerUi(): TemplateResponse {
        $response = new TemplateResponse(Application::APP_ID, 'swagger', [], 'blank');
        $csp = new \OCP\AppFramework\Http\EmptyContentSecurityPolicy();
        $csp->addAllowedScriptDomain("'unsafe-inline'");
        $csp->addAllowedScriptDomain("'self'");
        $csp->addAllowedStyleDomain("'unsafe-inline'");
        $csp->addAllowedStyleDomain("'self'");
        $csp->addAllowedConnectDomain("'self'");
        $csp->addAllowedImageDomain("'self'");
        $csp->addAllowedImageDomain("data:");
        $csp->addAllowedImageDomain("blob:");
        $response->setContentSecurityPolicy($csp);
        return $response;
    }
}
