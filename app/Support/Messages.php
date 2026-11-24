<?php

namespace App\Support;

/**
 * Arabic wording for the domain rules' messages (review M18 S10). Services keep their precise English messages for logs
 * and tests; whatever reaches a person on screen goes through here first. Messages ending in ": <detail>" keep the
 * detail after the Arabic text. An unknown message is shown as it is (better than hiding why something failed).
 */
final class Messages
{
    /** Exact messages. */
    private const EXACT = [
        'A composite unit needs at least two spaces of the same property.' => 'الوحدة المدمجة تحتاج مساحتين على الأقل من العقار نفسه.',
        'A payment must be positive.' => 'مبلغ الدفعة يجب أن يكون أكبر من صفر.',
        'A photo is mandatory for every asset.' => 'الصورة إلزامية لكل أصل.',
        'A property needs at least one owner.' => 'العقار يحتاج مالكاً واحداً على الأقل.',
        'A recharge needs the lease.' => 'تحميل التكلفة على المستأجر يحتاج العقد.',
        'A refusal to renew here needs an allowed reason code and a supporting document.' => 'رفض التجديد في هذه المنطقة يحتاج سبباً من الأسباب المعتمدة ومستنداً داعماً.',
        'A registered contract needs its Ejar contract number.' => 'العقد الموثّق يحتاج رقمه في منصة إيجار.',
        'A sublease must fall inside the original lease term.' => 'مدة التأجير من الباطن يجب أن تقع داخل مدة العقد الأصلي.',
        'A sublease rent cannot exceed the original lease rent.' => 'إيجار الباطن لا يتجاوز إيجار العقد الأصلي.',
        'A unit splits into at least two parts.' => 'تُقسَم الوحدة إلى جزأين على الأقل.',
        'Assign to either an internal technician or a vendor.' => 'أسند الأمر إلى فني داخلي أو إلى مورّد، لا إلى الاثنين.',
        'Completion needs after photos.' => 'إنهاء الأمر يحتاج صور ما بعد العمل.',
        'Condition is 1 to 5 for a present asset.' => 'حالة الأصل الموجود من 1 إلى 5.',
        'Deductions exceed the deposit held.' => 'الخصومات أكبر من التأمين المحفوظ.',
        'Dual approval is mandatory for this plan (PENDING-D10).' => 'الموافقة المزدوجة إلزامية في هذه الباقة.',
        'Every deduction from the deposit needs a supporting document.' => 'كل خصم من التأمين يحتاج مستنداً داعماً.',
        'Explicit consent is required to register.' => 'التسجيل يحتاج موافقتك الصريحة.',
        'Internal assignments go to technicians.' => 'الإسناد الداخلي يكون لفني.',
        'Invalid cost line.' => 'بند التكلفة غير صحيح.',
        'Invalid or used offer link.' => 'رابط العرض غير صحيح أو استُخدم من قبل.',
        'Invalid price.' => 'السعر غير صحيح.',
        'Name and phone are required.' => 'الاسم ورقم الجوال مطلوبان.',
        'No management agreement covers this owner for the month.' => 'لا يوجد عقد إدارة ساري لهذا المالك في هذا الشهر.',
        'Nobody approves an entry they made themselves.' => 'لا يعتمد أحد طلباً أدخله بنفسه.',
        'Nothing to transfer.' => 'لا يوجد مبلغ للتحويل.',
        'Notice is given by the lessor or the tenant.' => 'يقدّم الإشعار المؤجر أو المستأجر.',
        'Only issued invoices can be cancelled.' => 'تُلغى الفاتورة الصادرة فقط.',
        'Only the owner approves spending above the cap.' => 'الصرف فوق السقف يعتمده المالك وحده.',
        'Only two units of the same property can be merged.' => 'تُدمج وحدتان من العقار نفسه فقط.',
        'Payments go to the manager or the owner only — never the platform (REQ-0436).' => 'الدفعات تُستلم في حساب المكتب أو المالك فقط، ولا تستلم المنصة أموالاً.',
        'Rating is 1 to 5.' => 'التقييم من 1 إلى 5.',
        'Rent invoices are issued only for registered or active leases.' => 'تصدر فواتير الإيجار للعقود الموثّقة أو السارية فقط.',
        'Rent needs the usage in the contract (residential/commercial).' => 'حدّد استخدام العقد (سكني أو تجاري).',
        'Statutory work orders are created from the document vault (phase 2).' => 'أوامر العمل النظامية تُنشأ من خزنة المستندات.',
        'The checker must have higher authority than the maker.' => 'المعتمِد يجب أن يكون أعلى صلاحية من مقدّم الطلب.',
        'The creator cannot approve their own offer.' => 'من أنشأ العرض لا يعتمده بنفسه.',
        'The last registered rent of this unit is required inside a frozen area.' => 'في منطقة إيقاف الزيادة يلزم إدخال آخر إيجار موثّق لهذه الوحدة.',
        'The notice period has passed; the lease renews automatically.' => 'فات موعد الإشعار، والعقد يتجدد تلقائياً.',
        'This asset is registered in another unit.' => 'هذا الأصل مسجّل في وحدة أخرى.',
        'This batch is not awaiting confirmation.' => 'هذه الدفعة من البيانات لا تنتظر التأكيد.',
        'This color does not reach the required readability contrast with text.' => 'هذا اللون لا يحقق تبايناً كافياً لقراءة النص.',
        'This count is already submitted.' => 'هذا الجرد أُرسل من قبل.',
        'This count is not assigned to you.' => 'هذا الجرد ليس مسنداً إليك.',
        'This offer has expired.' => 'انتهت صلاحية هذا العرض.',
        'This request has already been decided.' => 'هذا الطلب بُتّ فيه من قبل.',
        'This schedule line is already invoiced.' => 'هذه الدفعة صدرت فاتورتها من قبل.',
        'This unit (or a unit sharing its spaces) is already leased, held or under maintenance for these dates.' => 'هذه الوحدة (أو وحدة تشاركها مساحاتها) مؤجرة أو محجوزة أو في صيانة في هذه التواريخ.',
        'This unit already has a founding inventory.' => 'لهذه الوحدة جرد تأسيسي من قبل.',
        'This unit is open for short-term letting only.' => 'هذه الوحدة معروضة للتأجير قصير المدة فقط.',
        'This user cannot decide approvals.' => 'هذا المستخدم لا يملك صلاحية الاعتماد.',
        'This user is not the approver of the current step.' => 'لست معتمِد هذه المرحلة.',
        'This work order is not assigned to you.' => 'أمر العمل هذا ليس مسنداً إليك.',
        'Unknown asset category/type.' => 'فئة الأصل أو نوعه غير معروف.',
        'Daily/weekly letting (tourist hospitality) is phase 3 (decision 6).' => 'التأجير اليومي والأسبوعي غير متاح في هذه المرحلة.',
        'Daily/weekly prices belong to short-term letting (phase 3, decision 6).' => 'الأسعار اليومية والأسبوعية للتأجير قصير المدة، وهو غير متاح في هذه المرحلة.',
        'Daily/weekly durations belong to phase 3.' => 'المدد اليومية والأسبوعية غير متاحة في هذه المرحلة.',
    ];

    /** Messages that start with a fixed text and carry a detail after it (regex => Arabic, with $1 for the detail). */
    private const PATTERNS = [
        '/^Inside a frozen area the rent cannot exceed the last registered rent of the unit: (.*)$/u' => 'في منطقة إيقاف الزيادة لا يتجاوز الإيجار آخر إيجار موثّق للوحدة: $1',
        '/^Renewal rent cannot exceed the last registered rent while the freeze applies: (.*)$/u' => 'إيجار التجديد لا يتجاوز آخر إيجار موثّق أثناء إيقاف الزيادة: $1',
        '/^A lease cannot go from (\w+) to (\w+)\.$/u' => 'لا يمكن نقل العقد من حالته الحالية إلى الحالة المطلوبة.',
        '/^Offer is (\w+)\.$/u' => 'لا يمكن تنفيذ هذا الإجراء على العرض في حالته الحالية.',
        '/^Work order is (\w+)\.$/u' => 'لا يمكن تنفيذ هذا الإجراء على أمر العمل في حالته الحالية.',
        '/^Ownership shares add up to (.*), not 1\.$/u' => 'مجموع حصص الملكية $1، ويجب أن يساوي العقار كاملاً.',
        '/^The (\w+) plan includes up to (\d+) units\.$/u' => 'باقتك تشمل حتى $2 وحدة. رقِّ الباقة لإضافة المزيد.',
        '/^Setting \[.*\] is locked by the subscriber administrator\.$/u' => 'هذا الإعداد مقفل من مدير المكتب.',
        '/^Missing confirmed value: (.*)$/u' => 'قيمة مطلوبة لم تُؤكَّد: $1',
        '/^Each share must be a fraction between 0 and 1\.$/u' => 'كل حصة يجب أن تكون كسراً بين صفر وواحد.',
    ];

    public static function ar(string $message): string
    {
        if (isset(self::EXACT[$message])) {
            return self::EXACT[$message];
        }
        foreach (self::PATTERNS as $re => $ar) {
            if (preg_match($re, $message)) {
                return preg_replace($re, $ar, $message);
            }
        }
        return $message;
    }
}
