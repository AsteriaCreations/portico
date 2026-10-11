<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A guest registration refused for a reason the desk should just be told
 * about (the username was just taken, the sponsor hit tonight's guest
 * limit), not a forged request. The message is already translated; the
 * caller shows it as a notification. Nothing was saved.
 */
class GuestRefused extends RuntimeException {}
