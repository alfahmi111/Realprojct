@php
    $c = $this->contract();
    $l = $c['l'];
    $dates = app(\App\Support\Dates\DateService::class);
    $g = fn ($d) => $d->format('Y-m-d');
    $h = fn ($d) => $dates->formatDate($d, 'hijri');
    $money = fn ($m) => \App\Support\Dates\NumberFormatter::money($m);
    $statusAr = \App\Filament\Manager\Resources\Leases\LeaseResource::STATUS_AR;
    $freq = ['monthly' => 'شهري', 'quarterly' => 'ربع سنوي', 'semiannual' => 'نصف سنوي', 'annual' => 'سنوي', 'single' => 'دفعة واحدة'];
    $usage = ['residential' => 'سكني', 'commercial' => 'تجاري'];
    $types = collect(\App\Properties\UnitTypes::TYPES)->map(fn ($t) => $t['ar']);
    $attrLabels = ['area_sqm' => 'مساحة الوحدة (م²)', 'rooms' => 'الغرف', 'bathrooms' => 'دورات المياه', 'furnished' => 'مؤثثة', 'electricity_meter_no' => 'رقم عداد الكهرباء', 'water_account_no' => 'رقم حساب المياه', 'parking_spaces' => 'المواقف', 'frontage_m' => 'الواجهة (م)', 'permitted_activity' => 'النشاط المسموح', 'municipal_license_no' => 'رقم الرخصة البلدية'];
    $lineState = ['scheduled' => ['مجدولة', 'wq-st-draft'], 'due' => ['مستحقة', 'wq-s-late'], 'paid' => ['مسددة', 'wq-st-active'], 'cancelled' => ['ملغاة', 'wq-st-cancelled']];
    $base = filament()->getCurrentPanel()->getUrl();
    $v = fn ($x) => ($x === null || $x === '') ? '—' : $x;
@endphp
<x-filament-panels::page class="wq-page wq-contract">
    <header class="wq-contract-head">
        <div>
            <a class="wq-back" href="{{ $base }}/leases">العقود</a>
            <h1>عقد إيجار الوحدة {{ $c['unit']->code }} في {{ $c['property']->name_ar }}</h1>
            <p>{{ $l->tenant->name_ar }} من {{ $g($c['starts']) }} إلى {{ $g($c['ends']) }}</p>
        </div>
        <div class="wq-contract-stamp">
            <span class="wq-chip wq-st-{{ $l->status }}">{{ $statusAr[$l->status] ?? $l->status }}</span>
            <strong>{{ $l->ejar_contract_number ?: 'لم يُوثّق في إيجار بعد' }}</strong>
            <small>رقم سجل العقد في إيجار</small>
        </div>
    </header>

    <section class="wq-sheet" aria-labelledby="s1">
        <h2 id="s1"><span>1</span>بيانات العقد</h2>
        <dl class="wq-fields">
            <div><dt>نوع العقد</dt><dd>{{ $l->parent_lease_id || $l->kind === 'renewal' ? 'مجدد' : 'جديد' }}</dd></div>
            <div><dt>الغرض</dt><dd>{{ $usage[$l->usage] ?? $l->usage }}</dd></div>
            <div><dt>مكان العقار</dt><dd>{{ $v($c['property']->city) }}</dd></div>
            <div><dt>بداية مدة الإيجار</dt><dd>{{ $g($c['starts']) }}<small>{{ $h($c['starts']) }}</small></dd></div>
            <div><dt>نهاية مدة الإيجار</dt><dd>{{ $g($c['ends']) }}<small>{{ $h($c['ends']) }}</small></dd></div>
            <div><dt>المدة</dt><dd>{{ $c['days'] }} يوماً</dd></div>
        </dl>
    </section>

    <div class="wq-sheet-two">
        <section class="wq-sheet" aria-labelledby="s2">
            <h2 id="s2"><span>2</span>المؤجر</h2>
            @foreach ($c['owners'] as $o)
                <dl class="wq-fields wq-fields-2">
                    <div><dt>الاسم</dt><dd>{{ $o['name'] }}</dd></div>
                    <div><dt>{{ $o['kind'] === 'company' ? 'السجل التجاري' : 'رقم الهوية' }}</dt><dd>{{ $v($o['id']) }}</dd></div>
                    @if (count($c['owners']) > 1)<div><dt>الحصة</dt><dd>{{ $o['share'] }}</dd></div>@endif
                </dl>
            @endforeach
            <h3>ممثل المؤجر</h3>
            <dl class="wq-fields wq-fields-2">
                <div><dt>مكتب الإدارة</dt><dd>{{ $c['office']?->name_ar }}</dd></div>
                <div><dt>السجل التجاري</dt><dd>{{ $v($c['office']?->commercial_registration) }}</dd></div>
            </dl>
        </section>
        <section class="wq-sheet" aria-labelledby="s3">
            <h2 id="s3"><span>3</span>المستأجر</h2>
            <dl class="wq-fields wq-fields-2">
                <div><dt>الاسم</dt><dd>{{ $l->tenant->name_ar }}</dd></div>
                <div><dt>{{ $l->tenant->kind === 'company' ? 'السجل التجاري' : 'رقم الهوية' }}</dt><dd>{{ $v($l->tenant->kind === 'company' ? $l->tenant->commercial_registration : $l->tenant->national_id) }}</dd></div>
                <div><dt>الجوال</dt><dd dir="ltr">{{ $v($l->tenant->phone) }}</dd></div>
                <div><dt>البريد الإلكتروني</dt><dd>{{ $v($l->tenant->email) }}</dd></div>
                <div class="wq-wide"><dt>العنوان الوطني</dt><dd>{{ $v($l->tenant->national_address) }}</dd></div>
            </dl>
        </section>
    </div>

    <div class="wq-sheet-two">
        <section class="wq-sheet" aria-labelledby="s4">
            <h2 id="s4"><span>4</span>العقار ومستند الملكية</h2>
            <dl class="wq-fields wq-fields-2">
                <div><dt>العقار</dt><dd>{{ $c['property']->name_ar }}</dd></div>
                <div><dt>رقم الصك</dt><dd>{{ $v($c['property']->deed_number) }}</dd></div>
                <div><dt>رقم العقار في إيجار</dt><dd>{{ $v($c['property']->ejar_property_number) }}</dd></div>
                <div><dt>رقم التسجيل العيني</dt><dd>{{ $v($c['property']->real_estate_registry_number) }}</dd></div>
                <div class="wq-wide"><dt>العنوان الوطني</dt><dd>{{ $v($c['property']->national_address) }}</dd></div>
            </dl>
        </section>
        <section class="wq-sheet" aria-labelledby="s5">
            <h2 id="s5"><span>5</span>الوحدة الإيجارية</h2>
            <dl class="wq-fields wq-fields-2">
                <div><dt>رقم الوحدة</dt><dd>{{ $c['unit']->code }}</dd></div>
                <div><dt>نوع الوحدة</dt><dd>{{ $types[$c['unit']->unit_type] ?? $c['unit']->unit_type }}</dd></div>
                <div><dt>الطابق</dt><dd>{{ $v($c['floor']?->name_ar) }}</dd></div>
                @foreach ($c['attributes'] as $k => $val)
                    <div><dt>{{ $attrLabels[$k] ?? $k }}</dt><dd>{{ is_bool($val) ? ($val ? 'نعم' : 'لا') : $v($val) }}</dd></div>
                @endforeach
            </dl>
        </section>
    </div>

    <section class="wq-sheet" aria-labelledby="s6">
        <h2 id="s6"><span>6</span>البيانات المالية</h2>
        <dl class="wq-fields">
            <div><dt>قيمة الإيجار السنوي</dt><dd>{{ $money($l->annual_rent_minor) }}</dd></div>
            <div><dt>دورة السداد</dt><dd>{{ $freq[$l->payment_frequency] ?? $l->payment_frequency }}</dd></div>
            <div><dt>عدد الدفعات</dt><dd>{{ count($c['lines']) }}</dd></div>
            <div><dt>الدفعة الدورية</dt><dd>{{ count($c['lines']) ? $money($c['lines'][0]['amount']) : '—' }}</dd></div>
            <div><dt>مبلغ الضمان</dt><dd>{{ $money($l->deposit_minor) }}<small>لا يدخل في القيمة الإجمالية</small></dd></div>
            <div><dt>إجمالي قيمة العقد</dt><dd class="wq-strong">{{ $money($c['total']) }}</dd></div>
        </dl>
    </section>

    <section class="wq-sheet" aria-labelledby="s7">
        <h2 id="s7"><span>7</span>جدول سداد الدفعات</h2>
        <div class="wq-table-wrap">
            <table class="wq-table">
                <thead><tr><th>#</th><th>تاريخ الاستحقاق (م)</th><th>تاريخ الاستحقاق (هـ)</th><th>الفترة الإيجارية</th><th>المبلغ</th><th>الحالة</th></tr></thead>
                <tbody>
                    @foreach ($c['lines'] as $line)
                        <tr>
                            <td>{{ $line['seq'] }}</td>
                            <td>{{ $g($line['due_on']) }}</td>
                            <td>{{ $h($line['due_on']) }}</td>
                            <td>{{ $line['days'] }} يوماً<small class="wq-sub">{{ $g($line['from']) }} — {{ $g($line['to']) }}</small></td>
                            <td>{{ $money($line['amount']) }}</td>
                            <td><span class="wq-chip {{ $lineState[$line['state']][1] }}">{{ $lineState[$line['state']][0] }}</span>@if ($line['invoice'])<small class="wq-sub">فاتورة {{ $line['invoice'] }}</small>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="wq-sheet" aria-labelledby="s8">
        <h2 id="s8"><span>8</span>مواعيد تهمك</h2>
        <dl class="wq-fields">
            <div><dt>آخر موعد لإشعار عدم التجديد</dt><dd>{{ $g($c['notice']) }}<small>{{ $h($c['notice']) }}</small></dd></div>
            <div><dt>التجديد</dt><dd>تلقائي لمدة مماثلة</dd><small>ما لم يُشعر أحد الطرفين قبل الموعد</small></div>
        </dl>
        <p class="wq-note">الإجراءات (التوثيق، إشعار عدم التجديد، التجديد، تسجيل الضمان) من <a href="{{ $base }}/leases">العقود</a> ثم «كل العقود والإجراءات».</p>
    </section>
</x-filament-panels::page>
