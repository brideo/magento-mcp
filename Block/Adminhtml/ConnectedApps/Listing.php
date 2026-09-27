<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Block\Adminhtml\ConnectedApps;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use UpturnStudio\Mcp\Model\ResourceModel\AccessTokenStorage;

/**
 * UpturnStudio_Mcp
 *
 * A plain, controller-rendered table - deliberately not a full Ui Component grid, per the
 * agreed scope for a first version of this screen.
 */
class Listing extends Template
{
    /**
     * @param Context $context
     * @param AccessTokenStorage $accessTokenStorage
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly AccessTokenStorage $accessTokenStorage,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return string
     */
    protected function _toHtml(): string
    {
        $connections = $this->accessTokenStorage->fetchActiveConnections();

        if ($connections === []) {
            return '<div class="message message-notice notice"><div>'
                . __('No AI connectors are currently authorized.') . '</div></div>';
        }

        $rows = '';
        foreach ($connections as $row) {
            $adminName = trim((string) ($row['firstname'] ?? '') . ' ' . (string) ($row['lastname'] ?? ''));
            $rows .= '<tr>'
                . '<td>' . $this->escapeHtml($row['client_name'] ?: $row['client_id']) . '</td>'
                . '<td>' . $this->escapeHtml($adminName !== '' ? $adminName : (string) $row['username']) . '</td>'
                . '<td>' . $this->escapeHtml((string) ($row['last_used_at'] ?? __('Never'))) . '</td>'
                . '<td>' . $this->escapeHtml((string) $row['created_at']) . '</td>'
                . '<td>'
                . '<form method="post" action="' . $this->escapeUrl($this->getUrl('*/*/revoke')) . '">'
                . '<input type="hidden" name="form_key" value="' . $this->escapeHtmlAttr($this->getFormKey()) . '">'
                . '<input type="hidden" name="entity_id" value="' . (int) $row['entity_id'] . '">'
                . '<button type="submit" class="action-secondary">' . __('Revoke') . '</button>'
                . '</form>'
                . '</td>'
                . '</tr>';
        }

        return '<table class="data-table admin__table-primary"><thead><tr>'
            . '<th>' . __('App') . '</th><th>' . __('Authorized By') . '</th><th>' . __('Last Used') . '</th>'
            . '<th>' . __('Authorized On') . '</th><th>' . __('Action') . '</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';
    }
}
