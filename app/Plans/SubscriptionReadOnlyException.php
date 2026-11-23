<?php

namespace App\Plans;

/** A user of a read-only office (subscription ended and grace period over, Q24) tried to save something. */
class SubscriptionReadOnlyException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('انتهى اشتراك المكتب ومهلة السماح، فالمنصة في وضع القراءة فقط: يمكنك الاطلاع على البيانات وتصديرها، ولا يُحفظ أي تعديل حتى يُجدَّد الاشتراك.');
    }
}
