<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Model\Mcp;

/**
 * UpturnStudio_Mcp
 *
 * Validates the protocol-level shape of an incoming MCP request per the 2026-07-28
 * specification: fully stateless, so every request must self-describe its protocol version
 * and client capabilities in params._meta rather than relying on any prior handshake.
 */
class RequestValidator
{
    public const PROTOCOL_VERSION = '2026-07-28';

    private const META_PROTOCOL_VERSION = 'io.modelcontextprotocol/protocolVersion';
    private const META_CLIENT_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';

    /**
     * @param array $body Decoded JSON-RPC request body
     * @param array $headers Relevant HTTP headers, with lower-cased keys - ignored entirely
     *     when $checkTransportHeaders is false
     * @param bool $checkTransportHeaders False for non-HTTP transports (stdio), where these
     *     headers do not exist at all - the params._meta checks below still apply regardless
     *     of transport, per spec
     * @return McpValidationError|null Null if the request is valid
     */
    public function validate(array $body, array $headers, bool $checkTransportHeaders = true): ?McpValidationError
    {
        $meta = $body['params']['_meta'] ?? null;
        if (!is_array($meta)) {
            return new McpValidationError(-32602, 'Missing required params._meta fields.', 400);
        }

        $protocolVersion = $meta[self::META_PROTOCOL_VERSION] ?? null;
        if ($protocolVersion === null) {
            return new McpValidationError(-32602, 'Missing ' . self::META_PROTOCOL_VERSION . '.', 400);
        }
        if ($protocolVersion !== self::PROTOCOL_VERSION) {
            return new McpValidationError(
                -32022,
                'Unsupported protocol version.',
                400,
                ['supported' => [self::PROTOCOL_VERSION], 'requested' => $protocolVersion]
            );
        }

        if (!isset($meta[self::META_CLIENT_CAPABILITIES]) || !is_array($meta[self::META_CLIENT_CAPABILITIES])) {
            return new McpValidationError(-32602, 'Missing ' . self::META_CLIENT_CAPABILITIES . '.', 400);
        }

        if ($checkTransportHeaders) {
            if (($headers['mcp-protocol-version'] ?? null) !== $protocolVersion) {
                return new McpValidationError(-32020, 'MCP-Protocol-Version header missing or does not match request body.', 400);
            }

            if (($headers['mcp-method'] ?? null) !== ($body['method'] ?? null)) {
                return new McpValidationError(-32020, 'Mcp-Method header missing or does not match request body.', 400);
            }
        }

        return null;
    }
}
