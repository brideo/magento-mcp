<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Model\Mcp;

use UpturnStudio\Mcp\Api\ToolExecutionException;
use UpturnStudio\Mcp\Api\ToolInterface;

/**
 * UpturnStudio_Mcp
 *
 * Aggregates every registered Api\ToolInterface implementation - deliberately not tied to
 * any specific tool by name here. Other modules add their own tools by contributing to this
 * class's "tools" DI argument from their own etc/di.xml, without any change to
 * UpturnStudio_Mcp itself. See Api\ToolInterface for the extension contract.
 */
class ToolRegistry
{
    /** @var ToolInterface[] Keyed by tool name, not by the DI argument's own array keys */
    private array $toolsByName = [];

    /**
     * @param ToolInterface[] $tools
     */
    public function __construct(array $tools = [])
    {
        foreach ($tools as $tool) {
            $this->toolsByName[$tool->getName()] = $tool;
        }
    }

    /**
     * @return array[] Tool definitions per the MCP tools/list result shape
     */
    public function listDefinitions(): array
    {
        $definitions = [];
        foreach ($this->toolsByName as $name => $tool) {
            $definitions[] = array_merge(['name' => $name], $tool->getDefinition());
        }
        return $definitions;
    }

    /**
     * @param string $name
     * @param int $adminUserId
     * @param array $arguments
     * @return array
     * @throws UnknownToolException
     * @throws ToolExecutionException
     */
    public function call(string $name, int $adminUserId, array $arguments): array
    {
        if (!isset($this->toolsByName[$name])) {
            throw new UnknownToolException($name);
        }

        return $this->toolsByName[$name]->execute($adminUserId, $arguments);
    }
}
