<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Controller\Index;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Psr\Log\LoggerInterface;
use UpturnStudio\Mcp\Model\Config;
use UpturnStudio\Mcp\Model\Mcp\JsonRpcDispatcher;
use UpturnStudio\Mcp\Model\Oauth\TokenAuthenticator;

/**
 * UpturnStudio_Mcp
 *
 * POST /mcp - the stateless MCP JSON-RPC endpoint. Authenticated via our own opaque bearer
 * token (never Magento's native admin token), so this explicitly opts out of Magento's
 * default form_key CSRF check via CsrfAwareActionInterface - this is a machine-to-machine
 * API call, not a browser form submission, and Magento requires POST actions to opt out
 * explicitly rather than defaulting to skipping the check.
 */
class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * @param RequestInterface $request
     * @param JsonFactory $resultJsonFactory
     * @param TokenAuthenticator $tokenAuthenticator
     * @param JsonRpcDispatcher $jsonRpcDispatcher
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly TokenAuthenticator $tokenAuthenticator,
        private readonly JsonRpcDispatcher $jsonRpcDispatcher,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $result = $this->resultJsonFactory->create();

        if (!$this->config->isEnabled()) {
            return $result->setHttpResponseCode(404)->setData(['error' => 'not_found']);
        }

        $authorizationHeader = $this->request->getHeader('Authorization');
        $tokenRow = $this->tokenAuthenticator->authenticate($authorizationHeader !== false ? $authorizationHeader : null);
        if ($tokenRow === null) {
            return $this->unauthorized($result);
        }

        $body = json_decode((string) $this->request->getContent(), true);
        if (!is_array($body)) {
            return $result->setHttpResponseCode(400)->setData([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32700, 'message' => 'Parse error.'],
            ]);
        }

        $dispatched = $this->jsonRpcDispatcher->dispatch($body, $this->extractHeaders(), (int) $tokenRow['admin_user_id']);

        return $result->setHttpResponseCode($dispatched['status'])->setData($dispatched['body']);
    }

    /**
     * @param ResultInterface $result
     * @return ResultInterface
     */
    private function unauthorized(ResultInterface $result): ResultInterface
    {
        try {
            $resourceMetadataUrl = $this->config->getPublicBaseUrl() . '/.well-known/oauth-protected-resource';
        } catch (\Throwable $e) {
            $this->logger->error(
                'UpturnStudio_Mcp: cannot build resource_metadata URL - ' . $e->getMessage(),
                ['exception' => $e]
            );
            return $result->setHttpResponseCode(500)->setData(['error' => 'server_error']);
        }

        $result->setHeader('WWW-Authenticate', sprintf('Bearer resource_metadata="%s"', $resourceMetadataUrl), true);
        return $result->setHttpResponseCode(401)->setData([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => -32000, 'message' => 'Unauthorized.'],
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function extractHeaders(): array
    {
        $headers = [];
        foreach (['MCP-Protocol-Version', 'Mcp-Method', 'Mcp-Name'] as $name) {
            $value = $this->request->getHeader($name);
            if ($value !== false) {
                $headers[strtolower($name)] = $value;
            }
        }
        return $headers;
    }
}
