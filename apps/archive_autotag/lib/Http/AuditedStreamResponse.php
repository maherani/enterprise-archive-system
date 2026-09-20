<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Http;

use OCA\ArchiveAutoTag\Service\AiFileService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;

/**
 * AuditedStreamResponse streams an archive file chunk-by-chunk to the client
 * while tracking real-time byte metrics, client disconnections (connection_aborted),
 * and updating the audit trail from STREAM_OPENED to TRANSFER_STARTED, COMPLETED or ABORTED.
 */
class AuditedStreamResponse extends Response implements ICallbackResponse {
    /**
     * @param resource $stream
     */
    public function __construct(
        private $stream,
        private int $fileSize,
        private string $requestId,
        private AiFileService $aiFileService,
        int $status = Http::STATUS_OK,
        array $headers = []
    ) {
        parent::__construct($status, $headers);
    }

    #[\Override]
    public function callback(IOutput $output): void {
        // Ensure script does not abruptly die on client disconnect so finally block can update audit trail
        ignore_user_abort(true);

        if ($output->getHttpResponseCode() === Http::STATUS_NOT_MODIFIED) {
            if (is_resource($this->stream)) {
                fclose($this->stream);
            }
            return;
        }

        if (!is_resource($this->stream)) {
            $output->setHttpResponseCode(Http::STATUS_INTERNAL_SERVER_ERROR);
            $this->aiFileService->updateTransferProgress(
                $this->requestId,
                0,
                'STREAM_FAILED',
                'FAILED',
                0,
                'Invalid file stream resource handle provided'
            );
            return;
        }

        $chunkSize = 8192; // 8 KB chunk buffer
        $bytesServed = 0;
        $startTime = hrtime(true);
        $status = 'COMPLETED';
        $errorMessage = null;

        // Testing hooks for simulation of network faults and early client aborts
        $simAbort = isset($_SERVER['HTTP_X_SIMULATE_STREAM_ABORT']) && $_SERVER['HTTP_X_SIMULATE_STREAM_ABORT'] === 'true';
        $simFail = isset($_SERVER['HTTP_X_SIMULATE_STREAM_FAILURE']) && $_SERVER['HTTP_X_SIMULATE_STREAM_FAILURE'] === 'true';
        $simAbortAfter = isset($_SERVER['HTTP_X_SIMULATE_ABORT_AFTER_BYTES']) ? (int)$_SERVER['HTTP_X_SIMULATE_ABORT_AFTER_BYTES'] : null;

        // Transition: Mark that stream is opened and transfer has started
        $this->aiFileService->updateTransferProgress(
            $this->requestId,
            0,
            'TRANSFER_STARTED',
            'STREAMING'
        );

        try {
            while (!feof($this->stream)) {
                // Check if client cancelled or disconnected early
                if (connection_aborted() !== 0 || $simAbort) {
                    $status = 'ABORTED';
                    $errorMessage = 'Client disconnected prematurely before transfer completion';
                    break;
                }

                if ($simFail) {
                    $status = 'FAILED';
                    $errorMessage = 'Simulated storage read error during chunk transfer';
                    break;
                }

                $buffer = fread($this->stream, $chunkSize);
                if ($buffer === false) {
                    $status = 'FAILED';
                    $errorMessage = 'Storage read error during chunk transfer';
                    break;
                }

                $len = strlen($buffer);
                if ($len === 0) {
                    break;
                }

                $output->setOutput($buffer);
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                @flush();

                $bytesServed += $len;

                if ($simAbortAfter !== null && $bytesServed >= $simAbortAfter) {
                    $status = 'ABORTED';
                    $errorMessage = "Client disconnected prematurely after {$bytesServed} bytes (simulated abort)";
                    break;
                }
            }
        } catch (\Throwable $t) {
            $status = 'FAILED';
            $errorMessage = $t->getMessage();
        } finally {
            if (is_resource($this->stream)) {
                fclose($this->stream);
            }

            $durationMs = (int)round((hrtime(true) - $startTime) / 1e6);

            // If not explicitly aborted or failed, verify full byte delivery
            if ($status !== 'ABORTED' && $status !== 'FAILED') {
                if ($this->fileSize > 0 && $bytesServed < $this->fileSize) {
                    $status = 'ABORTED';
                    $errorMessage = "Incomplete transfer: served {$bytesServed} of {$this->fileSize} bytes";
                } else {
                    $status = 'COMPLETED';
                }
            }

            $stage = ($status === 'COMPLETED') ? 'FINISHED' : 'TERMINATED';
            $this->aiFileService->updateTransferProgress(
                $this->requestId,
                $bytesServed,
                $status,
                $stage,
                $durationMs,
                $errorMessage
            );
        }
    }
}
