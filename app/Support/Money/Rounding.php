<?php

namespace App\Support\Money;

enum Rounding
{
    case HalfUp;       // half away from zero
    case HalfEven;     // banker's rounding
    case Up;           // toward +infinity
    case Down;         // toward -infinity
    case TowardZero;
}
