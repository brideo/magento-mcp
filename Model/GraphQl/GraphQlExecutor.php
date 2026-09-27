<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Model\GraphQl;

use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\Parser;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;
use UpturnStudio\Mcp\Model\Config;
use UpturnStudio\Mcp\Model\Oauth\MagentoAdminTokenProvider;

/**
 * UpturnStudio_Mcp
 *
 * Executes a GraphQL query on behalf of a specific admin user via an internal HTTP call to
 * this store's own real /graphql endpoint - not by invoking GraphQL's internal PHP classes
 * directly. That in-process approach was tried first and abandoned: Magento's GraphQL schema
 * generation depends on dozens of DI preferences scattered across etc/graphql/di.xml in ~49
 * modules (227 declarations, including some that reconfigure Magento\Framework\App\
 * FrontControllerInterface and page-cache plugins specifically for the graphql area), which
 * are only bound within that area's own DI scope - not globally, and not safely reproducible
 * in the frontend area without risking unrelated site behaviour (page caching, dispatch,
 * inventory/stock resolution). Calling the real endpoint reuses that area's own, already-
 * correct DI wiring with zero risk of missing or misapplying any of it.
 *
 * Read-only by design: any mutation operation is refused before anything is sent.
 */
class GraphQlExecutor
{
    private const TIMEOUT_SECONDS = 30;

    /**
     * @param CurlFactory $curlFactory
     * @param Config $config
     * @param MagentoAdminTokenProvider $adminTokenProvider
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly Config $config,
        private readonly MagentoAdminTokenProvider $adminTokenProvider,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param int $adminUserId
     * @param string $query
     * @param array $variables
     * @return array{data?: array, errors?: array} A genuine GraphQL result - "errors" here
     *     are ordinary GraphQL-level errors, not a tool-call failure. A refusal to even
     *     attempt the query throws instead; see GraphQlExecutionRefusedException.
     * @throws GraphQlExecutionRefusedException
     */
    public function execute(int $adminUserId, string $query, array $variables = []): array
    {
        if ($this->containsMutation($query)) {
            throw new GraphQlExecutionRefusedException(
                'This connector is read-only; mutation operations are not permitted.'
            );
        }

        $magentoAdminToken = $this->adminTokenProvider->getTokenFor($adminUserId);
        if ($magentoAdminToken === null) {
            throw new GraphQlExecutionRefusedException('The authorizing admin account is no longer active.');
        }

        try {
            $publicBaseUrl = $this->config->getPublicBaseUrl();
            $graphQlUrl = $publicBaseUrl . '/graphql';
            $urlParts = parse_url($publicBaseUrl);
            $host = $urlParts['host'] ?? '';
            $port = $urlParts['port'] ?? (($urlParts['scheme'] ?? 'https') === 'https' ? 443 : 80);

            $curl = $this->curlFactory->create();
            $curl->setTimeout(self::TIMEOUT_SECONDS);
            $curl->setHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $magentoAdminToken,
            ]);
            if ($host !== '') {
                // This is always a same-server, loopback call - go straight to 127.0.0.1
                // rather than through DNS or any external network path. The URL and Host
                // header still carry the real hostname, so Magento's store-detection and the
                // TLS handshake's SNI/certificate check are unaffected.
                $curl->setOption(CURLOPT_RESOLVE, ["{$host}:{$port}:127.0.0.1"]);
            }
            // Same reasoning as the loopback override above: this call never leaves the
            // server, so it doesn't depend on the calling PHP process's own CA trust store
            // being configured for this host (CLI and FPM SAPIs can differ here in practice).
            $curl->setOption(CURLOPT_SSL_VERIFYPEER, false);
            $curl->setOption(CURLOPT_SSL_VERIFYHOST, false);
            $curl->post($graphQlUrl, json_encode(['query' => $query, 'variables' => $variables], JSON_THROW_ON_ERROR));

            $status = $curl->getStatus();
            $decoded = json_decode((string) $curl->getBody(), true);

            if ($status !== 200 || !is_array($decoded)) {
                $this->logger->error(sprintf(
                    'UpturnStudio_Mcp: internal GraphQL call returned unexpected response - status=%d body=%s',
                    $status,
                    substr((string) $curl->getBody(), 0, 500)
                ));
                throw new GraphQlExecutionRefusedException('GraphQL execution failed.');
            }

            return $decoded;
        } catch (GraphQlExecutionRefusedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error(
                'UpturnStudio_Mcp: GraphQL execution failed - ' . $e->getMessage(),
                ['exception' => $e]
            );
            throw new GraphQlExecutionRefusedException('GraphQL execution failed.', 0, $e);
        }
    }

    /**
     * Parses the query and checks whether it contains any mutation operation. A query that
     * fails to parse is NOT treated as a mutation - it is left for the real /graphql endpoint
     * to report the real parse error, since refusing it here would give a misleading message.
     *
     * @param string $query
     * @return bool
     */
    private function containsMutation(string $query): bool
    {
        try {
            $document = Parser::parse($query);
        } catch (\Throwable) {
            return false;
        }

        foreach ($document->definitions as $definition) {
            if ($definition instanceof OperationDefinitionNode && $definition->operation === 'mutation') {
                return true;
            }
        }

        return false;
    }
}
