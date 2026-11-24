<?php

namespace App\Finance;

/** The external payment transaction is already recorded (M22, REQ-0316); screens show it on the reference field. */
class DuplicatePaymentException extends FinanceException
{
}
