<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Controller\Adminhtml\ConnectedApps;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use UpturnStudio\Mcp\Model\ResourceModel\AccessTokenStorage;

/**
 * UpturnStudio_Mcp
 *
 * POST-only (extends the legacy Action base class, so Magento's default form_key CSRF
 * check applies automatically - this is a same-origin authenticated admin form submission).
 */
class Revoke extends Action
{
    public const ADMIN_RESOURCE = 'UpturnStudio_Mcp::connector';

    /**
     * @param Context $context
     * @param AccessTokenStorage $accessTokenStorage
     */
    public function __construct(
        Context $context,
        private readonly AccessTokenStorage $accessTokenStorage
    ) {
        parent::__construct($context);
    }

    /**
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $entityId = (int) $this->getRequest()->getParam('entity_id');
        $row = $entityId > 0 ? $this->accessTokenStorage->findById($entityId) : null;

        if ($row !== null) {
            $this->accessTokenStorage->revokeFamily($row['family_id']);
            $this->messageManager->addSuccessMessage(__('The connection has been revoked.'));
        } else {
            $this->messageManager->addErrorMessage(__('Connection not found.'));
        }

        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        return $resultRedirect->setPath('*/*/index');
    }

    /**
     * @return bool
     */
    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed(self::ADMIN_RESOURCE);
    }
}
