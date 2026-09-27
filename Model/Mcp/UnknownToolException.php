<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Model\Mcp;

/**
 * UpturnStudio_Mcp
 */
class UnknownToolException extends \RuntimeException
{
    /**
     * @param string $toolName
     */
    public function __construct(string $toolName)
    {
        parent::__construct(sprintf('Unknown tool: %s', $toolName));
    }
}
