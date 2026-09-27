<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Controller\Index;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Psr\Log\LoggerInterface;
use UpturnStudio\Mcp\Model\Config;

/**
 * UpturnStudio_Mcp
 *
 * GET /.well-known/oauth-protected-resource - RFC 9728 protected resource metadata. Reached
 * via the custom bare-root Router, not ordinary frontName routing.
 */
class OauthProtectedResource implements HttpGetActionInterface
{
    /**
     * @param JsonFactory $resultJsonFactory
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly JsonFactory $resultJsonFactory,
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
            $result->setData([
                'resource' => $this->config->getMcpEndpointUrl(),
                'authorization_servers' => [$this->config->getPublicBaseUrl()],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error(
                'UpturnStudio_Mcp: protected resource metadata failed - ' . $e->getMessage(),
                ['exception' => $e]
            );
            $result->setHttpResponseCode(500)->setData(['error' => 'server_error']);
        }

        return $result;
    }
}
