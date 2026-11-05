<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * S-Etukortti no longer accepts the stored browser session. Not retryable:
 * only a fresh login, sent to the bot with /sgroup, fixes it.
 */
class SGroupSessionExpiredException extends RuntimeException {}
