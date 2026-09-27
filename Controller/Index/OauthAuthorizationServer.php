<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Controller\Index;

use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Psr\Log\LoggerInterface;
use UpturnStudio\Mcp\Model\Config;

/**
 * UpturnStudio_Mcp
 *
 * GET /.well-known/oauth-authorization-server - RFC 8414 authorization server metadata.
 * Reached via the custom bare-root Router, not ordinary frontName routing.
 */
class OauthAuthorizationServer implements HttpGetActionInterface
{
    /**
     * @param JsonFactory $resultJsonFactory
     * @param BackendUrlInterface $backendUrl
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly JsonFactory $resultJsonFactory,
        private readonly BackendUrlInterface $backendUrl,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $result = $this->resultJsonFactory->create();

        try {
            $issuer = $this->config->getPublicBaseUrl();
            $result->setData([
                'issuer' => $issuer,
                // Lives in the adminhtml area (needs the admin session cookie, which is
                // scoped to /admin) - built via the backend URL model so this respects any
                // custom admin path configuration rather than assuming a literal "/admin".
                'authorization_endpoint' => $this->backendUrl->getUrl('upturnstudio_mcp/oauth/authorize'),
                'token_endpoint' => $issuer . '/token',
                'registration_endpoint' => $issuer . '/register',
                'response_types_supported' => ['code'],
                'grant_types_supported' => ['authorization_code', 'refresh_token'],
                'code_challenge_methods_supported' => ['S256'],
                'token_endpoint_auth_methods_supported' => ['none'],
                'scopes_supported' => ['graphql:read'],
                'authorization_response_iss_parameter_supported' => true,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error(
                'UpturnStudio_Mcp: authorization server metadata failed - ' . $e->getMessage(),
                ['exception' => $e]
            );
            $result->setHttpResponseCode(500)->setData(['error' => 'server_error']);
        }

        return $result;
    }
}
