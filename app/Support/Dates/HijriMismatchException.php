<?php

namespace App\Support\Dates;

/** The Hijri date computed in the browser disagrees with the server's Umm al-Qura conversion. */
class HijriMismatchException extends \InvalidArgumentException
{
}
