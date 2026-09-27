<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Api;

/**
 * UpturnStudio_Mcp
 *
 * Implement this to expose an additional MCP tool through both transports (the HTTP/OAuth
 * connector and the stdio server). Register it by adding an entry to the "tools" argument on
 * UpturnStudio\Mcp\Model\Mcp\ToolRegistry from your OWN module's etc/di.xml - no change to
 * UpturnStudio_Mcp itself is needed:
 *
 * <type name="UpturnStudio\Mcp\Model\Mcp\ToolRegistry">
 *     <arguments>
 *         <argument name="tools" xsi:type="array">
 *             <item name="myTool" xsi:type="object">Vendor\Module\Model\Mcp\Tool\MyTool</item>
 *         </argument>
 *     </arguments>
 * </type>
 *
 * Tool names must be unique across every registered implementation - a later-merged entry
 * with the same getName() silently replaces an earlier one, matching how Magento's own
 * DI array arguments merge.
 */
interface ToolInterface
{
    /**
     * The tool's unique name, as advertised in tools/list and matched in tools/call.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * The tool's definition per the MCP tools/list result shape - everything except "name"
     * (description, inputSchema, and any other Tool fields the spec defines).
     *
     * @return array
     */
    public function getDefinition(): array;

    /**
     * Runs the tool. A normal return is wrapped as the tool's successful result
     * (isError: false) - throw ToolExecutionException to signal a refusal or failure instead
     * (isError: true), rather than encoding failure in the return value.
     *
     * @param int $adminUserId The admin identity this call is running as
     * @param array $arguments Decoded tools/call arguments
     * @return array
     * @throws ToolExecutionException
     */
    public function execute(int $adminUserId, array $arguments): array;
}
