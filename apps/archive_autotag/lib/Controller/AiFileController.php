<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Controller;

use OCA\ArchiveAutoTag\AppInfo\Application;
use OCA\ArchiveAutoTag\Service\AiFileService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
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
        $clientIp = (string)$this->request->getRemoteAddress();

        // 1. Authentication Check
        $auth = $this->aiFileService->authenticateRequest($this->request);
        if (!$auth['authenticated']) {
            $this->aiFileService->recordAudit(
                $requestId,
                'anonymous',
                $auth['client_id'],
                $fileId,
                '',
                $auth['auth_type'],
                'UNAUTHORIZED',
                $clientIp,
                0,
                $auth['error']
            );

            return new JSONResponse([
                'status' => 'error',
                'message' => $auth['error'],
                'code' => Http::STATUS_UNAUTHORIZED,
                'request_id' => $requestId,
            ], Http::STATUS_UNAUTHORIZED, [
                'WWW-Authenticate' => 'Basic realm="Enterprise Archive AI API", Bearer realm="Enterprise Archive AI API"',
                'X-Request-ID' => $requestId,
            ]);
        }

        $actorUid = (string)$auth['actor_uid'];
        $clientId = (string)$auth['client_id'];
        $authType = (string)$auth['auth_type'];

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
                $val['error_message']
            );

            return new JSONResponse([
                'status' => 'error',
                'message' => $val['error_message'],
                'code' => $resultCode,
                'request_id' => $requestId,
            ], $resultCode, [
                'X-Request-ID' => $requestId,
            ]);
        }

        /** @var \OCP\Files\Node $node */
        $node = $val['node'];
        $fileName = $node->getName();
        $fileSize = $node->getSize();
        $mimetype = $node->getMimetype();

        // 3. Record Successful Audit
        $this->aiFileService->recordAudit(
            $requestId,
            $actorUid,
            $clientId,
            $fileId,
            $fileName,
            $authType,
            'ALLOWED',
            $clientIp,
            $fileSize,
            null
        );

        // 4. Stream file without in-memory buffering
        $stream = $node instanceof File ? $node->fopen('rb') : fopen($node->getPath(), 'rb');
        if ($stream === false) {
            return new JSONResponse([
                'status' => 'error',
                'message' => 'Failed to open file stream from archive storage',
                'code' => Http::STATUS_INTERNAL_SERVER_ERROR,
                'request_id' => $requestId,
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
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
            'X-Actor-UID' => $actorUid,
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];

        return new StreamResponse($stream, Http::STATUS_OK, $headers);
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
            return new JSONResponse([
                'status' => 'error',
                'message' => $auth['error'],
                'code' => Http::STATUS_UNAUTHORIZED,
                'request_id' => $requestId,
            ], Http::STATUS_UNAUTHORIZED);
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
                'version' => '1.0.0',
                'description' => 'Secure, permission-enforced API providing air-gapped file delivery to internal AI assistants and automated workers without compromising archive ACLs or data boundaries.',
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
                        'description' => 'Dedicated AI Service Token for automated daemons and background agents'
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
                                'description' => 'Delegated user UID when calling via AI Service Token to enforce per-user RAG boundaries',
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
                                'description' => 'Authentication missing or invalid credentials',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/ErrorResponse']
                                    ]
                                ]
                            ],
                            '403' => [
                                'description' => 'Forbidden - Caller does not have permission to access requested archive file',
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
