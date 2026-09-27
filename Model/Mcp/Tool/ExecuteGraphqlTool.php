<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Model\Mcp\Tool;

use UpturnStudio\Mcp\Api\ToolExecutionException;
use UpturnStudio\Mcp\Api\ToolInterface;
use UpturnStudio\Mcp\Model\GraphQl\GraphQlExecutor;

/**
 * UpturnStudio_Mcp
 */
class ExecuteGraphqlTool implements ToolInterface
{
    private const NAME = 'execute_graphql';

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
            'description' => 'Runs a read-only GraphQL query against this Magento store, using the '
                . 'permissions of the admin who authorized this connection. Mutation operations are refused.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'A GraphQL query document.'],
                    'variables' => ['type' => 'object', 'description' => 'GraphQL variables, if any.'],
                ],
                'required' => ['query'],
            ],
        ];
    }

    /**
     * @param int $adminUserId
     * @param array $arguments
     * @return array
     * @throws ToolExecutionException
     */
    public function execute(int $adminUserId, array $arguments): array
    {
        $query = $arguments['query'] ?? null;
        if (!is_string($query) || $query === '') {
            throw new ToolExecutionException('The "query" argument is required and must be a non-empty string.');
        }

        $variables = $arguments['variables'] ?? [];
        if (!is_array($variables)) {
            throw new ToolExecutionException('The "variables" argument must be an object.');
        }

        return $this->graphQlExecutor->execute($adminUserId, $query, $variables);
    }
}
