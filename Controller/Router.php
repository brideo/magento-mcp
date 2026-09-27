<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Controller;

use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Route\ConfigInterface;
use Magento\Framework\App\Router\ActionList;
use Magento\Framework\App\RouterInterface;

/**
 * UpturnStudio_Mcp
 *
 * Matches the four bare-root OAuth/well-known paths, which have no frontName segment and so
 * cannot be reached by ordinary Magento routing. Modeled directly on
 * Magento\Securitytxt\Controller\Router, which solves exactly this problem for
 * /.well-known/security.txt.
 *
 * /authorize deliberately is NOT handled here - it needs the admin's session cookie, which
 * is scoped to the /admin path, so it lives as a real adminhtml controller instead (see
 * Controller\Adminhtml\Oauth\Authorize) where that cookie actually arrives and Magento's own
 * session/ACL machinery is correctly wired.
 */
class Router implements RouterInterface
{
    private const PATH_PROTECTED_RESOURCE = '.well-known/oauth-protected-resource';
    private const PATH_AUTHORIZATION_SERVER = '.well-known/oauth-authorization-server';
    private const PATH_REGISTER = 'register';
    private const PATH_TOKEN = 'token';

    /**
     * @param ActionFactory $actionFactory
     * @param ActionList $actionList
     * @param ConfigInterface $routeConfig
     */
    public function __construct(
        private readonly ActionFactory $actionFactory,
        private readonly ActionList $actionList,
        private readonly ConfigInterface $routeConfig
    ) {
    }

    /**
     * @param RequestInterface $request
     * @return ActionInterface|null
     */
    public function match(RequestInterface $request): ?ActionInterface
    {
        $identifier = trim($request->getPathInfo(), '/');
        $method = $request->getMethod();

        $action = match (true) {
            $identifier === self::PATH_PROTECTED_RESOURCE && $method === 'GET' => 'oauthprotectedresource',
            $identifier === self::PATH_AUTHORIZATION_SERVER && $method === 'GET' => 'oauthauthorizationserver',
            $identifier === self::PATH_REGISTER && $method === 'POST' => 'register',
            $identifier === self::PATH_TOKEN && $method === 'POST' => 'token',
            default => null,
        };

        if ($action === null) {
            return null;
        }

        $modules = $this->routeConfig->getModulesByFrontName('mcp');
        if (empty($modules)) {
            return null;
        }

        $actionClassName = $this->actionList->get($modules[0], null, 'index', $action);
        return $actionClassName ? $this->actionFactory->create($actionClassName) : null;
    }
}
