<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A check-in refused by a re-check inside its own transaction -- the
 * building filled up, or an add-on sold out, after the desk's screen last
 * rendered. Throwing it rolls the transaction back, so nothing is recorded
 * or charged; the caller shows $title and $body as a warning. Both are
 * already translated.
 */
class CheckInRefused extends RuntimeException
{
    public function __construct(public readonly string $title, public readonly string $body)
    {
        parent::__construct($title);
    }
}
