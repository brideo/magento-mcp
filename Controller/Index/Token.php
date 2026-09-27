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
use UpturnStudio\Mcp\Model\Config;
use UpturnStudio\Mcp\Model\Oauth\AuthorizationCodeIssuer;
use UpturnStudio\Mcp\Model\Oauth\OauthGrantException;
use UpturnStudio\Mcp\Model\Oauth\RedirectUriValidator;
use UpturnStudio\Mcp\Model\Oauth\Throttler;
use UpturnStudio\Mcp\Model\Oauth\TokenIssuer;

/**
 * UpturnStudio_Mcp
 *
 * POST /token - RFC 6749 SS4.1.3, form-urlencoded only (distinct from /register's JSON body).
 * Machine-to-machine, no cookies involved, so this explicitly opts out of Magento's default
 * form_key CSRF check via CsrfAwareActionInterface.
 */
class Token implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const THROTTLE_BUCKET = 'token';

    /**
     * @param RequestInterface $request
     * @param JsonFactory $resultJsonFactory
     * @param RemoteAddress $remoteAddress
     * @param AuthorizationCodeIssuer $codeIssuer
     * @param TokenIssuer $tokenIssuer
     * @param RedirectUriValidator $redirectUriValidator
     * @param Throttler $throttler
     * @param LoggerInterface $logger
     * @param Config $config
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly RemoteAddress $remoteAddress,
        private readonly AuthorizationCodeIssuer $codeIssuer,
        private readonly TokenIssuer $tokenIssuer,
        private readonly RedirectUriValidator $redirectUriValidator,
        private readonly Throttler $throttler,
        private readonly LoggerInterface $logger,
        private readonly Config $config
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
        /** @var \Magento\Framework\Controller\Result\Json $result */
        $result = $this->resultJsonFactory->create();
        $result->setHeader('Cache-Control', 'no-store', true);
        $result->setHeader('Pragma', 'no-cache', true);

        if (!$this->config->isEnabled()) {
            return $result->setHttpResponseCode(404)->setData(['error' => 'not_found']);
        }

        $ip = (string) ($this->remoteAddress->getRemoteAddress() ?: 'unknown');
        $grantType = (string) $this->request->getParam('grant_type', '');
        $clientId = (string) $this->request->getParam('client_id', '');

        if (!$this->throttler->isAllowed(self::THROTTLE_BUCKET, $ip)
            || !$this->throttler->isAllowed(self::THROTTLE_BUCKET, $clientId)) {
            return $result->setHttpResponseCode(429)->setHeader('Retry-After', '300', true)
                ->setData(['error' => 'slow_down']);
        }

        $contentType = (string) $this->request->getHeader('Content-Type');
        if (!str_contains($contentType, 'application/x-www-form-urlencoded')) {
            return $result->setHttpResponseCode(415)->setData([
                'error' => 'invalid_request',
                'error_description' => 'Content-Type must be application/x-www-form-urlencoded.',
            ]);
        }

        try {
            $issued = match ($grantType) {
                'authorization_code' => $this->handleAuthorizationCode(),
                'refresh_token' => $this->handleRefreshToken(),
                default => throw new OauthGrantException('unsupported_grant_type'),
            };
        } catch (OauthGrantException $e) {
            $this->throttler->recordFailure(self::THROTTLE_BUCKET, $ip);
            $this->throttler->recordFailure(self::THROTTLE_BUCKET, $clientId);
            return $result->setHttpResponseCode(400)->setData(['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error('UpturnStudio_Mcp: token endpoint failed - ' . $e->getMessage(), ['exception' => $e]);
            return $result->setHttpResponseCode(500)->setData(['error' => 'server_error']);
        }

        return $result->setHttpResponseCode(200)->setData($issued['response']);
    }

    /**
     * @return array{entity_id: int, response: array}
     * @throws OauthGrantException
     */
    private function handleAuthorizationCode(): array
    {
        $code = (string) $this->request->getParam('code', '');
        $clientId = (string) $this->request->getParam('client_id', '');
        $redirectUri = (string) $this->request->getParam('redirect_uri', '');
        $codeVerifier = (string) $this->request->getParam('code_verifier', '');

        if ($code === '' || $clientId === '' || $redirectUri === '' || $codeVerifier === '') {
            throw new OauthGrantException('invalid_request');
        }

        // The stored code is bound to the NORMALIZED redirect_uri (e.g. a loopback URI has
        // its port stripped) - normalize the incoming value the same way before comparing,
        // or a legitimate loopback client would always fail this check.
        $normalizedRedirectUri = $this->redirectUriValidator->normalize($redirectUri);
        if ($normalizedRedirectUri === null) {
            throw new OauthGrantException('invalid_grant');
        }

        $codeRow = $this->codeIssuer->consume($code, $clientId, $normalizedRedirectUri, $codeVerifier);

        $issued = $this->tokenIssuer->issue($clientId, (int) $codeRow['admin_user_id'], $codeRow['resource'] ?? null);
        $this->codeIssuer->recordIssuedToken($code, $issued['entity_id']);

        return $issued;
    }

    /**
     * @return array{entity_id: int, response: array}
     * @throws OauthGrantException
     */
    private function handleRefreshToken(): array
    {
        $refreshToken = (string) $this->request->getParam('refresh_token', '');
        if ($refreshToken === '') {
            throw new OauthGrantException('invalid_request');
        }

        return $this->tokenIssuer->redeemRefreshToken($refreshToken);
    }
}
