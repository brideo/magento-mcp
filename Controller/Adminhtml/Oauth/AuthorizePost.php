<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Controller\Adminhtml\Oauth;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Psr\Log\LoggerInterface;
use UpturnStudio\Mcp\Model\Config;
use UpturnStudio\Mcp\Model\Oauth\AuthorizationCodeIssuer;
use UpturnStudio\Mcp\Model\Oauth\HtmlPageRenderer;
use UpturnStudio\Mcp\Model\Oauth\RedirectUriValidator;
use UpturnStudio\Mcp\Model\ResourceModel\OauthClientStorage;

/**
 * UpturnStudio_Mcp
 *
 * POST admin/upturnstudio_mcp/oauth/authorizepost - the consent decision. This IS a
 * same-origin, cookie-session-bound admin form submission, so (extending the legacy Action
 * base class, like every other adminhtml controller) it automatically gets Magento's default
 * form_key CSRF check - no CsrfAwareActionInterface override needed here.
 */
class AuthorizePost extends Action
{
    private const ACL_RESOURCE = 'UpturnStudio_Mcp::connector';

    /**
     * @param Context $context
     * @param AdminSession $adminSession
     * @param OauthClientStorage $clientStorage
     * @param RedirectUriValidator $redirectUriValidator
     * @param AuthorizationCodeIssuer $codeIssuer
     * @param HtmlPageRenderer $htmlPageRenderer
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly AdminSession $adminSession,
        private readonly OauthClientStorage $clientStorage,
        private readonly RedirectUriValidator $redirectUriValidator,
        private readonly AuthorizationCodeIssuer $codeIssuer,
        private readonly HtmlPageRenderer $htmlPageRenderer,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    /**
     * Same rationale as Authorize::_isAllowed() - custom messaging instead of Magento's
     * generic redirect/denied page.
     *
     * @return bool
     */
    protected function _isAllowed(): bool
    {
        return true;
    }

    /**
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        if (!$this->config->isEnabled()) {
            return $this->htmlResult($this->htmlPageRenderer->renderErrorPage('The AI connector is disabled.'));
        }

        $clientId = (string) $this->getRequest()->getParam('client_id', '');
        $redirectUri = (string) $this->getRequest()->getParam('redirect_uri', '');
        $codeChallenge = (string) $this->getRequest()->getParam('code_challenge', '');
        $codeChallengeMethod = (string) $this->getRequest()->getParam('code_challenge_method', '');
        $state = $this->getRequest()->getParam('state');
        $resource = $this->getRequest()->getParam('resource') ?: null;
        $scope = $this->getRequest()->getParam('scope') ?: null;
        $decision = (string) $this->getRequest()->getParam('decision', '');

        $client = $clientId !== '' ? $this->clientStorage->findByClientId($clientId) : null;
        if ($client === null) {
            return $this->htmlResult($this->htmlPageRenderer->renderErrorPage('Unknown client_id.'));
        }

        $registeredUris = json_decode((string) $client['redirect_uris'], true) ?: [];
        if (!$this->redirectUriValidator->matches($redirectUri, $registeredUris)) {
            return $this->htmlResult(
                $this->htmlPageRenderer->renderErrorPage('redirect_uri does not match any URI registered for this client.')
            );
        }
        $normalizedRedirectUri = (string) $this->redirectUriValidator->normalize($redirectUri);

        if (!$this->adminSession->isLoggedIn()) {
            return $this->htmlResult(
                $this->htmlPageRenderer->renderErrorPage('Your admin session has expired. Please start again.')
            );
        }

        $adminUserId = (int) $this->adminSession->getUser()->getId();
        if (!$this->_authorization->isAllowed(self::ACL_RESOURCE)) {
            return $this->htmlResult(
                $this->htmlPageRenderer->renderErrorPage('Your admin role is not permitted to authorize AI connectors.')
            );
        }

        if ($decision !== 'allow') {
            return $this->redirectTo($normalizedRedirectUri, ['error' => 'access_denied', 'state' => $state]);
        }

        try {
            $issuer = $this->config->getPublicBaseUrl();
        } catch (\Throwable $e) {
            $this->logger->error('UpturnStudio_Mcp: authorize post failed - ' . $e->getMessage(), ['exception' => $e]);
            return $this->htmlResult(
                $this->htmlPageRenderer->renderErrorPage('The connector is not fully configured yet.')
            );
        }

        $code = $this->codeIssuer->issue(
            $clientId,
            $normalizedRedirectUri,
            $codeChallenge,
            $codeChallengeMethod,
            $resource,
            $scope,
            $adminUserId
        );

        return $this->redirectTo($normalizedRedirectUri, ['code' => $code, 'state' => $state, 'iss' => $issuer]);
    }

    /**
     * @param string $html
     * @return Raw
     */
    private function htmlResult(string $html): Raw
    {
        /** @var Raw $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setHeader('Content-Type', 'text/html; charset=UTF-8', true);
        $result->setHeader('X-Frame-Options', 'DENY', true);
        $result->setHeader('Content-Security-Policy', "frame-ancestors 'none'", true);
        $result->setContents($html);
        return $result;
    }

    /**
     * @param string $redirectUri
     * @param array $params
     * @return Redirect
     */
    private function redirectTo(string $redirectUri, array $params): Redirect
    {
        /** @var Redirect $result */
        $result = $this->resultRedirectFactory->create();
        $filtered = array_filter($params, static fn ($v) => $v !== null && $v !== '');
        $result->setUrl($redirectUri . '?' . http_build_query($filtered));
        return $result;
    }
}
