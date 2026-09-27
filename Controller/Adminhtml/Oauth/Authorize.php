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
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\UrlInterface;
use UpturnStudio\Mcp\Model\Config;
use UpturnStudio\Mcp\Model\Oauth\HtmlPageRenderer;
use UpturnStudio\Mcp\Model\Oauth\RedirectUriValidator;
use UpturnStudio\Mcp\Model\ResourceModel\OauthClientStorage;

/**
 * UpturnStudio_Mcp
 *
 * GET admin/upturnstudio_mcp/oauth/authorize - lives in the adminhtml area deliberately:
 * Magento's admin session cookie is scoped to the /admin path, so a bare-root frontend route
 * (as originally attempted) never receives it, and that area's ACL/session DI wiring is also
 * only correctly configured within adminhtml. Validates client_id, redirect_uri and PKCE
 * before doing anything else; only redirects to redirect_uri once it is validated - an
 * unknown client or mismatched redirect_uri renders a local error page instead.
 */
class Authorize extends Action
{
    private const ACL_RESOURCE = 'UpturnStudio_Mcp::connector';

    /**
     * Exempts this GET action from Magento's admin "secret key" URL check
     * (Magento\Backend\App\Request\BackendValidator -> AbstractAction::_processUrlKeys()).
     * That check exists to stop CSRF via guessable admin GET links, keyed by a per-admin
     * secret Magento itself embeds in every URL it builds - but this URL is built by an
     * external OAuth client, which has no way to know that secret. PKCE + the exact
     * redirect_uri/client_id validation above are this endpoint's own CSRF-equivalent
     * protection instead.
     *
     * @var string[]
     */
    protected $_publicActions = ['authorize'];

    /**
     * @param Context $context
     * @param AdminSession $adminSession
     * @param UrlInterface $url
     * @param FormKey $formKey
     * @param OauthClientStorage $clientStorage
     * @param RedirectUriValidator $redirectUriValidator
     * @param HtmlPageRenderer $htmlPageRenderer
     * @param Config $config
     */
    public function __construct(
        Context $context,
        private readonly AdminSession $adminSession,
        private readonly UrlInterface $url,
        private readonly FormKey $formKey,
        private readonly OauthClientStorage $clientStorage,
        private readonly RedirectUriValidator $redirectUriValidator,
        private readonly HtmlPageRenderer $htmlPageRenderer,
        private readonly Config $config
    ) {
        parent::__construct($context);
    }

    /**
     * Bypasses Magento's automatic ACL-gate-then-redirect-to-login: this controller handles
     * both "not logged in" and "wrong role" itself, with its own messaging and a "Continue"
     * link, since Magento's default login redirect has no return-to-URL support and would
     * otherwise silently drop the pending OAuth request parameters.
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
            return $this->errorPage('The AI connector is disabled.');
        }

        $clientId = (string) $this->getRequest()->getParam('client_id', '');
        $redirectUri = (string) $this->getRequest()->getParam('redirect_uri', '');
        $codeChallenge = (string) $this->getRequest()->getParam('code_challenge', '');
        $codeChallengeMethod = (string) $this->getRequest()->getParam('code_challenge_method', '');
        $state = $this->getRequest()->getParam('state');
        $resource = $this->getRequest()->getParam('resource');
        $scope = $this->getRequest()->getParam('scope');

        $client = $clientId !== '' ? $this->clientStorage->findByClientId($clientId) : null;
        if ($client === null) {
            return $this->errorPage('Unknown client_id.');
        }

        $registeredUris = json_decode((string) $client['redirect_uris'], true) ?: [];
        if (!$this->redirectUriValidator->matches($redirectUri, $registeredUris)) {
            return $this->errorPage('redirect_uri does not match any URI registered for this client.');
        }
        $normalizedRedirectUri = (string) $this->redirectUriValidator->normalize($redirectUri);

        if ($codeChallengeMethod !== 'S256' || $codeChallenge === '') {
            return $this->redirectWithError($normalizedRedirectUri, 'invalid_request', $state);
        }

        if (!$this->adminSession->isLoggedIn()) {
            $html = $this->htmlPageRenderer->renderContinuePage(
                $this->getUrl('*/auth/login'),
                $this->url->getCurrentUrl()
            );
            return $this->htmlResult($html);
        }

        if (!$this->_authorization->isAllowed(self::ACL_RESOURCE)) {
            return $this->errorPage(
                'Your admin role is not permitted to authorize AI connectors. Ask an administrator '
                . 'to grant the "AI Connector (MCP)" permission.'
            );
        }

        $isLoopback = str_starts_with($normalizedRedirectUri, 'http://127.0.0.1')
            || str_starts_with($normalizedRedirectUri, 'http://localhost');

        $hiddenFields = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'state' => (string) $state,
            'resource' => (string) $resource,
            'scope' => (string) $scope,
        ];

        $html = $this->htmlPageRenderer->renderConsentPage(
            (string) ($client['client_name'] ?: 'This application'),
            (string) parse_url($normalizedRedirectUri, PHP_URL_HOST),
            $isLoopback,
            $this->getUrl('*/*/authorizepost'),
            $hiddenFields,
            $this->formKey->getFormKey()
        );

        return $this->htmlResult($html);
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
     * @param string $message
     * @return Raw
     */
    private function errorPage(string $message): Raw
    {
        return $this->htmlResult($this->htmlPageRenderer->renderErrorPage($message));
    }

    /**
     * @param string $redirectUri
     * @param string $error
     * @param mixed $state
     * @return Redirect
     */
    private function redirectWithError(string $redirectUri, string $error, mixed $state): Redirect
    {
        /** @var Redirect $result */
        $result = $this->resultRedirectFactory->create();
        $params = array_filter(['error' => $error, 'state' => $state], static fn ($v) => $v !== null && $v !== '');
        $result->setUrl($redirectUri . '?' . http_build_query($params));
        return $result;
    }
}
