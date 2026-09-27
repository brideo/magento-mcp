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
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Psr\Log\LoggerInterface;
use UpturnStudio\Mcp\Model\Oauth\ClientRegistrar;
use UpturnStudio\Mcp\Model\Oauth\Throttler;

/**
 * UpturnStudio_Mcp
 *
 * POST /register - RFC 7591 Dynamic Client Registration. Deliberately unauthenticated
 * (Claude must reach this before any admin session exists) - the "Connected Apps" admin
 * screen and the consent page's redirect-URI display are the mitigations for that. Explicitly
 * opts out of Magento's default form_key CSRF check via CsrfAwareActionInterface, since this
 * is a machine-to-machine call with no cookies/session involved.
 */
class Register implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const THROTTLE_BUCKET = 'register';

    /**
     * @param RequestInterface $request
     * @param JsonFactory $resultJsonFactory
     * @param RemoteAddress $remoteAddress
     * @param ClientRegistrar $clientRegistrar
     * @param Throttler $throttler
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly RemoteAddress $remoteAddress,
        private readonly ClientRegistrar $clientRegistrar,
        private readonly Throttler $throttler,
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
        $ip = (string) ($this->remoteAddress->getRemoteAddress() ?: 'unknown');

        if (!$this->throttler->isAllowed(self::THROTTLE_BUCKET, $ip)) {
            return $result->setHttpResponseCode(429)->setHeader('Retry-After', '300', true)
                ->setData(['error' => 'too_many_requests']);
        }

        $contentType = (string) $this->request->getHeader('Content-Type');
        if (!str_contains($contentType, 'application/json')) {
            $this->throttler->recordFailure(self::THROTTLE_BUCKET, $ip);
            return $result->setHttpResponseCode(415)->setData([
                'error' => 'invalid_client_metadata',
                'error_description' => 'Content-Type must be application/json.',
            ]);
        }

        $payload = json_decode((string) $this->request->getContent(), true);
        if (!is_array($payload)) {
            $this->throttler->recordFailure(self::THROTTLE_BUCKET, $ip);
            return $result->setHttpResponseCode(400)->setData([
                'error' => 'invalid_client_metadata',
                'error_description' => 'Malformed JSON body.',
            ]);
        }

        try {
            $response = $this->clientRegistrar->register($payload);
        } catch (\InvalidArgumentException $e) {
            $this->throttler->recordFailure(self::THROTTLE_BUCKET, $ip);
            return $result->setHttpResponseCode(400)->setData([
                'error' => 'invalid_client_metadata',
                'error_description' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('UpturnStudio_Mcp: client registration failed - ' . $e->getMessage(), ['exception' => $e]);
            return $result->setHttpResponseCode(500)->setData(['error' => 'server_error']);
        }

        return $result->setHttpResponseCode(201)->setData($response);
    }
}
