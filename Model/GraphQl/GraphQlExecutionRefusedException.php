<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Model\GraphQl;

use UpturnStudio\Mcp\Api\ToolExecutionException;

/**
 * UpturnStudio_Mcp
 *
 * Thrown when GraphQlExecutor refuses to even attempt a query (read-only violation, inactive
 * admin, or an internal execution failure) - distinct from a normal GraphQL-level {data,
 * errors} response, which is returned normally, not thrown. Extends the general
 * ToolExecutionException so any ToolInterface built on top of GraphQlExecutor gets correct
 * isError reporting for free, without needing to catch this GraphQL-specific type itself.
 */
class GraphQlExecutionRefusedException extends ToolExecutionException
{
}
