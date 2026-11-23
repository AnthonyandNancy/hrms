<?php

declare(strict_types=1);

namespace App\Shared\Money;

/**
 * Rounding modes operate on the exact decimal representation. HALF_UP rounds
 * ties away from zero, HALF_EVEN rounds ties to the even neighbour, DOWN
 * truncates toward zero, and UP moves away from zero whenever a remainder
 * exists.
 */
enum RoundingMode: string
{
    case HALF_UP = 'HALF_UP';
    case HALF_EVEN = 'HALF_EVEN';
    case DOWN = 'DOWN';
    case UP = 'UP';

    public function round(Decimal $value, int $scale): Decimal
    {
        return $value->roundTo($scale, $this);
    }
}
