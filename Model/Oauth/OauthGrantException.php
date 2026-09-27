<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Model\Oauth;

/**
 * UpturnStudio_Mcp
 *
 * Thrown for any failed authorization_code or refresh_token grant. The exception message
 * is always a valid RFC 6749 error code (e.g. "invalid_grant") - callers surface it directly
 * as the token endpoint's "error" field.
 */
class OauthGrantException extends \RuntimeException
{
}
