<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Model\Mcp\Tool;

use UpturnStudio\Mcp\Api\ToolExecutionException;
use UpturnStudio\Mcp\Api\ToolInterface;
use UpturnStudio\Mcp\Model\GraphQl\GraphQlExecutor;
use UpturnStudio\Mcp\Model\PageSpeed\PageSpeedInsightsClient;

/**
 * UpturnStudio_Mcp
 *
 * Reports Core Web Vitals for a store page and, separately, resolves that page's URL to a
 * Magento entity (product, category, or CMS page) via the real /graphql `route` query - so a
 * caller knows exactly which record a follow-up query or edit should target. Read-only, like
 * every other tool in this module: it identifies the entity, it does not modify it.
 */
class CheckCoreWebVitalsTool implements ToolInterface
{
    private const NAME = 'check_core_web_vitals';

    private const ROUTE_QUERY = <<<'GRAPHQL'
        query($url: String!) {
            route(url: $url) {
                relative_url
                redirect_code
                type
                ... on ProductInterface { id sku name }
                ... on CategoryInterface { id uid name }
                ... on CmsPage { identifier title }
            }
        }
        GRAPHQL;

    /**
     * @param PageSpeedInsightsClient $pageSpeedInsightsClient
     * @param GraphQlExecutor $graphQlExecutor
     */
    public function __construct(
        private readonly PageSpeedInsightsClient $pageSpeedInsightsClient,
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
            'description' => 'Reports Core Web Vitals (via Google PageSpeed Insights) for a given store page '
                . 'URL, and identifies which Magento entity - product, category, or CMS page, including its '
                . 'entity ID - that page is, so a follow-up query or edit can target it directly.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'url' => ['type' => 'string', 'description' => 'The absolute URL of the page to check.'],
                    'strategy' => [
                        'type' => 'string',
                        'enum' => ['mobile', 'desktop'],
                        'description' => 'Which PageSpeed Insights device strategy to use. Defaults to "mobile".',
                    ],
                ],
                'required' => ['url'],
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
        $url = $arguments['url'] ?? null;
        if (!is_string($url) || $url === '' || !preg_match('#^https?://#i', $url)) {
            throw new ToolExecutionException('The "url" argument is required and must be an absolute http(s):// URL.');
        }

        $strategy = $arguments['strategy'] ?? 'mobile';
        if (!in_array($strategy, ['mobile', 'desktop'], true)) {
            throw new ToolExecutionException('The "strategy" argument must be "mobile" or "desktop".');
        }

        $coreWebVitals = $this->pageSpeedInsightsClient->analyze($url, $strategy);

        return [
            'url' => $url,
            'strategy' => $strategy,
            'coreWebVitals' => $coreWebVitals,
            'entity' => $this->resolveEntity($adminUserId, $url),
        ];
    }

    /**
     * Best-effort - a page with no matching url_rewrite (e.g. the homepage) is a normal
     * result, not a failure, so this returns null rather than throwing.
     *
     * @param int $adminUserId
     * @param string $url
     * @return array|null
     * @throws ToolExecutionException
     */
    private function resolveEntity(int $adminUserId, string $url): ?array
    {
        $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        $result = $this->graphQlExecutor->execute($adminUserId, self::ROUTE_QUERY, ['url' => $path]);
        $route = $result['data']['route'] ?? null;
        $type = $route['type'] ?? null;

        return match ($type) {
            'PRODUCT' => ['type' => 'PRODUCT', 'id' => $route['id'] ?? null, 'sku' => $route['sku'] ?? null,
                'name' => $route['name'] ?? null],
            'CATEGORY' => ['type' => 'CATEGORY', 'id' => $route['id'] ?? null, 'uid' => $route['uid'] ?? null,
                'name' => $route['name'] ?? null],
            'CMS_PAGE' => ['type' => 'CMS_PAGE', 'identifier' => $route['identifier'] ?? null,
                'title' => $route['title'] ?? null],
            default => null,
        };
    }
}
