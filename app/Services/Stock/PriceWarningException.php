<?php

namespace App\Services\Stock;

/** A price that looks like a slip. It is not refused: the person confirms and tries again. */
class PriceWarningException extends StockException {}
