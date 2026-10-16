<?php

namespace App\Services\Payments;

use RuntimeException;

/** The gateway said no, could not be reached, or an answer did not match. The message is a short fixed sentence that is safe to show. */
class PaymentException extends RuntimeException {}
