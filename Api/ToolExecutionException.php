<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Api;

/**
 * UpturnStudio_Mcp
 *
 * Thrown by a ToolInterface::execute() implementation to signal that the tool call itself
 * was refused or failed (surfaced to the MCP client as isError: true) - distinct from a
 * normal return value that happens to contain domain-level errors (e.g. a GraphQL
 * {errors: [...]} response), which is not a tool-call failure and should just be returned.
 */
class ToolExecutionException extends \RuntimeException
{
}
