<?php

namespace App\Services\Couriers;

use RuntimeException;

/** A courier company said no, or could not be reached. The message is a short fixed sentence that is safe to show (it never repeats what they sent back). */
class CourierException extends RuntimeException {}
