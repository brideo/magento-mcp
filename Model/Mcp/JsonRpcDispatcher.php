<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Model\Mcp;

use Psr\Log\LoggerInterface;
use UpturnStudio\Mcp\Api\ToolExecutionException;

/**
 * UpturnStudio_Mcp
 *
 * Dispatches a decoded JSON-RPC request per the stateless 2026-07-28 MCP specification.
 * Implements exactly the methods this connector needs: server/discover (required by spec),
 * tools/list, and tools/call.
 */
class JsonRpcDispatcher
{
    private const SERVER_NAME = 'UpturnStudio_Mcp';
    private const SERVER_VERSION = '1.0.0';

    /**
     * @param RequestValidator $requestValidator
     * @param ToolRegistry $toolRegistry
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RequestValidator $requestValidator,
        private readonly ToolRegistry $toolRegistry,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array $body Decoded JSON-RPC request body
     * @param array $headers Relevant HTTP headers, with lower-cased keys - ignored entirely
     *     when $checkTransportHeaders is false
     * @param int $adminUserId The admin identity resolved from the bearer token (HTTP) or
     *     from --admin-user (stdio)
     * @param bool $checkTransportHeaders False for non-HTTP transports (stdio), where MCP
     *     transport headers like Mcp-Method/Mcp-Name do not exist at all
     * @return array{status: int, body: array}
     */
    public function dispatch(array $body, array $headers, int $adminUserId, bool $checkTransportHeaders = true): array
    {
        $id = $body['id'] ?? null;
        $method = $body['method'] ?? null;

        if (!is_string($method) || $method === '') {
            return $this->errorResponse($id, -32600, 'Invalid Request.', 400);
        }

        $validationError = $this->requestValidator->validate($body, $headers, $checkTransportHeaders);
        if ($validationError !== null) {
            return $this->errorResponse(
                $id,
                $validationError->code,
                $validationError->message,
                $validationError->httpStatus,
                $validationError->data
            );
        }

        try {
            return match ($method) {
                'server/discover' => $this->handleDiscover($id),
                'tools/list' => $this->handleToolsList($id),
                'tools/call' => $this->handleToolsCall($id, $body, $headers, $adminUserId, $checkTransportHeaders),
                default => $this->errorResponse($id, -32601, 'Method not found.', 404),
            };
        } catch (\Throwable $e) {
            $this->logger->error('UpturnStudio_Mcp: JSON-RPC dispatch failed - ' . $e->getMessage(), ['exception' => $e]);
            return $this->errorResponse($id, -32603, 'Internal error.', 500);
        }
    }

    /**
     * @param int|string|null $id
     * @return array{status: int, body: array}
     */
    private function handleDiscover(int|string|null $id): array
    {
        return $this->successResponse($id, [
            'resultType' => 'complete',
            'supportedVersions' => [RequestValidator::PROTOCOL_VERSION],
            // ServerCapabilities.tools is a {listChanged?: boolean} OBJECT, never an array -
            // an empty PHP array json_encodes as [] regardless of intent, so this must be
            // cast to force {} rather than [] on the wire.
            'capabilities' => ['tools' => (object) []],
            // DiscoverResult extends CacheableResult, which requires these two fields - a
            // client that strictly validates the result shape (e.g. the reference Inspector)
            // will silently reject the whole result, not just warn, if they are missing.
            'ttlMs' => 3600000,
            'cacheScope' => 'public',
        ]);
    }

    /**
     * @param int|string|null $id
     * @return array{status: int, body: array}
     */
    private function handleToolsList(int|string|null $id): array
    {
        return $this->successResponse($id, [
            'resultType' => 'complete',
            'tools' => $this->toolRegistry->listDefinitions(),
            'ttlMs' => 3600000,
            'cacheScope' => 'private',
        ]);
    }

    /**
     * @param int|string|null $id
     * @param array $body
     * @param array $headers
     * @param int $adminUserId
     * @param bool $checkTransportHeaders
     * @return array{status: int, body: array}
     */
    private function handleToolsCall(
        int|string|null $id,
        array $body,
        array $headers,
        int $adminUserId,
        bool $checkTransportHeaders
    ): array {
        $params = $body['params'] ?? [];
        $name = $params['name'] ?? null;
        if (!is_string($name) || $name === '') {
            return $this->errorResponse($id, -32602, 'Missing tool name.', 400);
        }

        if ($checkTransportHeaders && ($headers['mcp-name'] ?? null) !== $name) {
            return $this->errorResponse($id, -32020, 'Mcp-Name header missing or does not match params.name.', 400);
        }

        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments)) {
            return $this->errorResponse($id, -32602, 'arguments must be an object.', 400);
        }

        try {
            $result = $this->toolRegistry->call($name, $adminUserId, $arguments);
        } catch (UnknownToolException $e) {
            return $this->errorResponse($id, -32602, $e->getMessage(), 400);
        } catch (ToolExecutionException $e) {
            return $this->successResponse($id, [
                'resultType' => 'complete',
                'isError' => true,
                'content' => [['type' => 'text', 'text' => $e->getMessage()]],
            ]);
        }

        return $this->successResponse($id, [
            'resultType' => 'complete',
            'isError' => false,
            'structuredContent' => $result,
            'content' => [['type' => 'text', 'text' => json_encode($result)]],
        ]);
    }

    /**
     * @param int|string|null $id
     * @param array $result
     * @return array{status: int, body: array}
     */
    private function successResponse(int|string|null $id, array $result): array
    {
        $result['_meta'] = [
            'io.modelcontextprotocol/serverInfo' => ['name' => self::SERVER_NAME, 'version' => self::SERVER_VERSION],
        ];
        return ['status' => 200, 'body' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]];
    }

    /**
     * @param int|string|null $id
     * @param int $code
     * @param string $message
     * @param int $httpStatus
     * @param array|null $data
     * @return array{status: int, body: array}
     */
    private function errorResponse(int|string|null $id, int $code, string $message, int $httpStatus, ?array $data = null): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }
        return ['status' => $httpStatus, 'body' => ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error]];
    }
}
