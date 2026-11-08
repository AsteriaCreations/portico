<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A private rental booking refused for a reason the desk should just be told
 * about (the member can't book, the room is taken, the hours are out of
 * range), not a forged request. The message is already translated; the
 * caller shows it as a notification.
 */
class PrivateRentalRefused extends RuntimeException {}
