<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Model\Mcp\Tool;

use GraphQL\Type\Introspection;
use UpturnStudio\Mcp\Api\ToolInterface;
use UpturnStudio\Mcp\Model\GraphQl\GraphQlExecutor;

/**
 * UpturnStudio_Mcp
 */
class IntrospectGraphqlSchemaTool implements ToolInterface
{
    private const NAME = 'introspect_graphql_schema';

    /**
     * @param GraphQlExecutor $graphQlExecutor
     */
    public function __construct(
        private readonly GraphQlExecutor $graphQlExecutor
    ) {
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * @return array
     */
    public function getDefinition(): array
    {
        return [
            'description' => 'Returns this store\'s full GraphQL schema via standard introspection, so '
                . 'available queries and types can be discovered before calling execute_graphql.',
            // properties is a JSON Schema object, not an array - cast forces {} on the wire
            // instead of the [] an empty PHP array would otherwise produce.
            'inputSchema' => ['type' => 'object', 'properties' => (object) []],
        ];
    }

    /**
     * @param int $adminUserId
     * @param array $arguments
     * @return array
     */
    public function execute(int $adminUserId, array $arguments): array
    {
        return $this->graphQlExecutor->execute($adminUserId, Introspection::getIntrospectionQuery());
    }
}
