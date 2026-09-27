<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Model\Oauth;

/**
 * UpturnStudio_Mcp
 *
 * Small, self-contained HTML pages for the /authorize flow. Deliberately plain markup
 * returned via Result\Raw rather than Magento's layout/block/template system - these are a
 * handful of one-off pages, not a themeable storefront surface.
 */
class HtmlPageRenderer
{
    /**
     * Shown when the admin isn't logged in yet. Magento's admin login has no generic
     * "return to this page" redirect, so this relies on the admin session cookie being
     * shared across browser tabs: the admin opens login in a new tab, then clicks Continue
     * to simply re-request this exact /authorize URL.
     *
     * @param string $adminLoginUrl
     * @param string $continueUrl
     * @return string
     */
    public function renderContinuePage(string $adminLoginUrl, string $continueUrl): string
    {
        $body = '<h1>Sign in required</h1>'
            . '<p>An admin account must be signed in to authorize this connection.</p>'
            . '<p><a class="button allow" href="' . $this->esc($adminLoginUrl) . '" target="_blank" rel="noopener">Sign in to Magento Admin</a></p>'
            . '<p>Once you\'ve signed in, come back here and continue:</p>'
            . '<p><a class="button allow" href="' . $this->esc($continueUrl) . '">Continue</a></p>';
        return $this->shell('Sign in required', $body);
    }

    /**
     * @param string $clientName
     * @param string $redirectUriHost
     * @param bool $isLoopbackRedirect
     * @param string $formActionUrl
     * @param array<string, string> $hiddenFields
     * @param string $formKey
     * @return string
     */
    public function renderConsentPage(
        string $clientName,
        string $redirectUriHost,
        bool $isLoopbackRedirect,
        string $formActionUrl,
        array $hiddenFields,
        string $formKey
    ): string {
        $hiddenInputs = '';
        foreach ($hiddenFields as $name => $value) {
            $hiddenInputs .= sprintf(
                '<input type="hidden" name="%s" value="%s">',
                $this->esc($name),
                $this->esc($value)
            );
        }
        $hiddenInputs .= '<input type="hidden" name="form_key" value="' . $this->esc($formKey) . '">';

        $warning = '';
        if ($isLoopbackRedirect) {
            $warning = '<div class="warn">This app will redirect back to a program running on your own '
                . 'computer (<code>' . $this->esc($redirectUriHost) . '</code>). Any local process can bind '
                . 'that address and claim to be the client - only continue if you started this connection yourself.</div>';
        }

        $body = '<h1>Authorize connection</h1>'
            . '<p><strong>' . $this->esc($clientName) . '</strong> is requesting read-only access to this '
            . 'store\'s GraphQL API, using your own admin permissions.</p>'
            . '<p>You will be redirected to: <code>' . $this->esc($redirectUriHost) . '</code></p>'
            . $warning
            . '<form method="post" action="' . $this->esc($formActionUrl) . '">'
            . $hiddenInputs
            . '<button class="allow" type="submit" name="decision" value="allow">Allow</button>'
            . '<button class="deny" type="submit" name="decision" value="deny">Deny</button>'
            . '</form>';
        return $this->shell('Authorize connection', $body);
    }

    /**
     * @return string
     */
    public function renderDeniedPage(): string
    {
        return $this->shell('Access denied', '<h1>Access denied</h1><p>You denied this connection request. You can close this window.</p>');
    }

    /**
     * @param string $message
     * @return string
     */
    public function renderErrorPage(string $message): string
    {
        return $this->shell('Request error', '<h1>Request error</h1><p>' . $this->esc($message) . '</p>');
    }

    /**
     * @param string $title
     * @param string $bodyHtml
     * @return string
     */
    private function shell(string $title, string $bodyHtml): string
    {
        return '<!doctype html><html><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $this->esc($title) . '</title>'
            . '<style>'
            . 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;max-width:520px;'
            . 'margin:64px auto;padding:0 20px;color:#1a1a1a;line-height:1.5}'
            . 'code{background:#f0f0f0;padding:2px 6px;border-radius:4px}'
            . 'button,a.button{display:inline-block;padding:10px 22px;border-radius:6px;border:none;'
            . 'font-size:15px;cursor:pointer;text-decoration:none;margin:4px 8px 4px 0}'
            . '.allow{background:#1a7f37;color:#fff}.deny{background:#e5e5e5;color:#1a1a1a}'
            . '.warn{background:#fff3cd;border:1px solid #e6c250;padding:12px 14px;border-radius:6px;margin:16px 0}'
            . '</style></head><body>' . $bodyHtml . '</body></html>';
    }

    /**
     * @param string $value
     * @return string
     */
    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
