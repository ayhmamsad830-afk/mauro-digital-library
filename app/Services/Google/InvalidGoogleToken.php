<?php

declare(strict_types=1);

namespace App\Services\Google;

use RuntimeException;

/** Verification failure; the message is a short machine reason stored in oauth_audit_events. */
final class InvalidGoogleToken extends RuntimeException
{
}
