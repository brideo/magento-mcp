<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Model\UrlRewrite;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Psr\Log\LoggerInterface;
use UpturnStudio\Mcp\Api\ToolExecutionException;

/**
 * UpturnStudio_Mcp
 *
 * Resolves a store page URL to the Magento entity behind it via a direct url_rewrite lookup
 * (UrlFinderInterface) plus the matching entity repository - deliberately not via GraphQL.
 * Unlike execute_graphql/introspect_graphql_schema, this doesn't need GraphQL's schema or
 * resolvers at all, just a plain table lookup and a repository fetch, both globally bound
 * services with no area-scoping problem - so there's no reason to pay for a loopback HTTP
 * call (and depend on the public base URL being loopback-reachable) just for this.
 */
class EntityResolver
{
    /**
     * @param UrlFinderInterface $urlFinder
     * @param StoreManagerInterface $storeManager
     * @param ProductRepositoryInterface $productRepository
     * @param CategoryRepositoryInterface $categoryRepository
     * @param PageRepositoryInterface $pageRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly UrlFinderInterface $urlFinder,
        private readonly StoreManagerInterface $storeManager,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly PageRepositoryInterface $pageRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Best-effort - a URL with no matching url_rewrite (e.g. the homepage), or one that
     * points at an entity that's since been deleted, returns null rather than throwing.
     *
     * @param string $url
     * @return array|null
     * @throws ToolExecutionException
     */
    public function resolve(string $url): ?array
    {
        $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');

        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
            $rewrite = $this->urlFinder->findOneByData(['request_path' => $path, 'store_id' => $storeId]);
            if ($rewrite === null) {
                return null;
            }

            $entityId = (int) $rewrite->getEntityId();

            return match ($rewrite->getEntityType()) {
                'product' => $this->resolveProduct($entityId),
                'category' => $this->resolveCategory($entityId),
                'cms-page' => $this->resolveCmsPage($entityId),
                default => null,
            };
        } catch (NoSuchEntityException) {
            // The url_rewrite row is stale (points at a deleted entity) - same as "no entity".
            return null;
        } catch (\Throwable $e) {
            $this->logger->error(
                'UpturnStudio_Mcp: entity resolution failed - ' . $e->getMessage(),
                ['exception' => $e]
            );
            throw new ToolExecutionException('Entity resolution failed.', 0, $e);
        }
    }

    /**
     * @param int $id
     * @return array
     */
    private function resolveProduct(int $id): array
    {
        $product = $this->productRepository->getById($id);
        return ['type' => 'PRODUCT', 'id' => $id, 'sku' => $product->getSku(), 'name' => $product->getName()];
    }

    /**
     * @param int $id
     * @return array
     */
    private function resolveCategory(int $id): array
    {
        $category = $this->categoryRepository->get($id);
        return ['type' => 'CATEGORY', 'id' => $id, 'name' => $category->getName()];
    }

    /**
     * @param int $id
     * @return array
     */
    private function resolveCmsPage(int $id): array
    {
        $page = $this->pageRepository->getById($id);
        return ['type' => 'CMS_PAGE', 'id' => $id, 'identifier' => $page->getIdentifier(), 'title' => $page->getTitle()];
    }
}
