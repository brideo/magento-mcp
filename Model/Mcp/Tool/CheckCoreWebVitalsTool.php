<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Model\Mcp\Tool;

use UpturnStudio\Mcp\Api\ToolExecutionException;
use UpturnStudio\Mcp\Api\ToolInterface;
use UpturnStudio\Mcp\Model\PageSpeed\PageSpeedInsightsClient;
use UpturnStudio\Mcp\Model\UrlRewrite\EntityResolver;

/**
 * UpturnStudio_Mcp
 *
 * Reports Core Web Vitals for a store page and, separately, resolves that page's URL to a
 * Magento entity (product, category, or CMS page) - so a caller knows exactly which record a
 * follow-up query or edit should target. Read-only, like every other tool in this module: it
 * identifies the entity, it does not modify it.
 */
class CheckCoreWebVitalsTool implements ToolInterface
{
    private const NAME = 'check_core_web_vitals';

    /**
     * @param PageSpeedInsightsClient $pageSpeedInsightsClient
     * @param EntityResolver $entityResolver
     */
    public function __construct(
        private readonly PageSpeedInsightsClient $pageSpeedInsightsClient,
        private readonly EntityResolver $entityResolver
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

        return [
            'url' => $url,
            'strategy' => $strategy,
            'coreWebVitals' => $this->pageSpeedInsightsClient->analyze($url, $strategy),
            'entity' => $this->entityResolver->resolve($url),
        ];
    }
}
