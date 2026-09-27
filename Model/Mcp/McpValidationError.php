<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Model\Mcp;

/**
 * UpturnStudio_Mcp
 *
 * A JSON-RPC / MCP protocol-level validation failure, carrying everything needed to build
 * the error response: the JSON-RPC error code, message, HTTP status, and optional data.
 */
final class McpValidationError
{
    /**
     * @param int $code
     * @param string $message
     * @param int $httpStatus
     * @param array|null $data
     */
    public function __construct(
        public readonly int $code,
        public readonly string $message,
        public readonly int $httpStatus,
        public readonly ?array $data = null
    ) {
    }
}
