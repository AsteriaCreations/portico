<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A day pass sale refused for a reason the desk should just be told about
 * (today: the member already holds that pass), not a forged request. The
 * message is already translated; the caller shows it as a notification.
 */
class DayPassRefused extends RuntimeException {}
