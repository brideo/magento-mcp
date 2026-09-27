<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Controller\Adminhtml\ConnectedApps;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * UpturnStudio_Mcp
 *
 * Admin > System > AI Connector (MCP) - lists currently-authorized connections with a
 * revoke action per row. Exists because DCR is open/unauthenticated by design (Claude must
 * reach it before any admin session exists) - this screen is the mitigation for that, giving
 * admins visibility and a revoke button after the fact.
 */
class Index extends Action
{
    public const ADMIN_RESOURCE = 'UpturnStudio_Mcp::connector';

    /**
     * @param Context $context
     * @param PageFactory $resultPageFactory
     */
    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    /**
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        /** @var Page $resultPage */
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('UpturnStudio_Mcp::connected_apps');
        $resultPage->getConfig()->getTitle()->prepend(__('AI Connector (MCP) - Connected Apps'));
        return $resultPage;
    }
}
