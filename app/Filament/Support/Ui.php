<?php

namespace App\Filament\Support;

use App\Support\Dates\NumberFormatter;
use App\Support\Money\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/** Shared screen building blocks: money in halalas (REQ-0165), archive instead of delete (REQ-0168), service errors. */
final class Ui
{
    /** A money field typed as riyals ("1250.50") and stored as integer halalas — never a float. */
    public static function money(string $name, string $label): TextInput
    {
        return TextInput::make($name)->label($label)
            ->regex('/^\d{1,12}(\.\d{1,2})?$/')
            ->suffix(fn () => NumberFormatter::currencyLabel())
            ->formatStateUsing(fn ($state) => $state instanceof Money ? $state->toDecimalString() : ($state === null ? null : Money::of((int) $state)->toDecimalString()))
            ->dehydrateStateUsing(fn ($state) => $state === null || $state === '' ? null : Money::parse((string) $state)->minor());
    }

    /** Optional property photo (owner request 2026-11-23): private disk, per-subscriber folder, served through RLS. */
    public static function propertyPhoto(): \Filament\Forms\Components\FileUpload
    {
        return \Filament\Forms\Components\FileUpload::make('photo_path')->label('صورة المبنى (اختيارية)')
            ->image()->imageEditor(false)->disk('local')->visibility('private')
            ->directory(fn () => 'properties/' . (auth()->user()?->subscriber_id ?? 'x'))
            ->maxSize(4096)->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->helperText('تظهر في الرئيسية وصفحة العقار ومحتوى المبنى. JPG أو PNG حتى 4 ميغابايت.');
    }

    /**
     * Location and national address (owner request 2026-11-24; spec 4.5 step 1, REQ-0448): a map instead of typing
     * latitude/longitude, the short national address, and — once SPL is linked — the full address fetched from the pin.
     */
    public static function location(): \Filament\Schemas\Components\Section
    {
        $gw = fn () => app(\App\Integrations\NationalAddress\NationalAddressGateway::class);
        $linked = fn () => in_array(\App\Integrations\IntegrationStatus::driver('national_address'), ['simulator', 'live'], true);
        $fill = function (?array $a, \Filament\Schemas\Components\Utilities\Set $set) {
            if (! $a) {
                \Filament\Notifications\Notification::make()->title('لا عنوان وطني مسجّل في هذا الموقع')->warning()->send();
                return;
            }
            foreach (['short', 'building_no', 'street', 'district', 'city', 'postal_code', 'additional_no'] as $k) {
                $set('national_address.' . $k, $a[$k] ?? null);
            }
            if (! empty($a['city'])) {
                $set('city', $a['city']);
            }
            \Filament\Notifications\Notification::make()->title('جُلب العنوان الوطني: ' . ($a['short'] ?? ''))->success()->send();
        };
        return \Filament\Schemas\Components\Section::make('الموقع والعنوان الوطني')
            // SPL lookup deferred by the owner 2026-11-24 (paid subscription): the buttons below stay hidden while
            // integrations.national_address is "none"; the short address is typed by hand.
            ->description(fn () => $linked() ? 'حدّد المبنى على الخريطة، ثم اجلب عنوانه الوطني المختصر من سبل، أو اكتبه.' : 'حدّد المبنى على الخريطة، واكتب عنوانه الوطني المختصر.')
            ->schema([
                \Filament\Forms\Components\ViewField::make('map_picker')->view('filament.forms.map-picker')->dehydrated(false)->columnSpanFull(),
                \Filament\Forms\Components\Hidden::make('latitude'),
                \Filament\Forms\Components\Hidden::make('longitude'),
                \Filament\Forms\Components\TextInput::make('national_address.short')->label('العنوان الوطني المختصر')->placeholder('RRRD2929')
                    ->regex('/^[A-Za-z]{4}\d{4}$/')->validationMessages(['regex' => 'العنوان المختصر أربعة أحرف ثم أربعة أرقام، مثل RRRD2929.'])
                    ->extraInputAttributes(['dir' => 'ltr', 'style' => 'text-transform:uppercase'])->dehydrateStateUsing(fn ($state) => $state ? strtoupper($state) : null)
                    ->helperText(fn () => $linked() ? 'أو اجلبه من الموقع بالزر.' : 'تجده في تطبيق «العنوان الوطني» أو على لوحة المبنى.'),
                \Filament\Schemas\Components\Actions::make([
                    \Filament\Actions\Action::make('fetchAddress')->label('اجلب العنوان من الموقع')->icon(Heroicon::OutlinedMapPin)->color('gray')
                        ->visible($linked)
                        ->action(function (\Filament\Schemas\Components\Utilities\Get $get, \Filament\Schemas\Components\Utilities\Set $set) use ($gw, $fill) {
                            if (! $get('latitude') || ! $get('longitude')) {
                                \Filament\Notifications\Notification::make()->title('حدّد الموقع على الخريطة أولاً')->warning()->send();
                                return;
                            }
                            $fill($gw()->byCoordinates((float) $get('latitude'), (float) $get('longitude')), $set);
                        }),
                    \Filament\Actions\Action::make('fetchByShort')->label('تحقق من العنوان المختصر')->icon(Heroicon::OutlinedCheckBadge)->color('gray')
                        ->visible($linked)
                        ->action(function (\Filament\Schemas\Components\Utilities\Get $get, \Filament\Schemas\Components\Utilities\Set $set) use ($gw, $fill) {
                            $short = strtoupper((string) $get('national_address.short'));
                            $a = $gw()->byShortAddress($short);
                            $fill($a, $set);
                            if ($a && ! empty($a['latitude'])) {
                                $set('latitude', $a['latitude']);
                                $set('longitude', $a['longitude']);
                            }
                        }),
                ])->verticallyAlignEnd(),
                \Filament\Schemas\Components\Fieldset::make('تفاصيل العنوان')->schema([
                    \Filament\Forms\Components\TextInput::make('national_address.building_no')->label('رقم المبنى')->regex('/^\d{4}$/'),
                    \Filament\Forms\Components\TextInput::make('national_address.street')->label('الشارع'),
                    \Filament\Forms\Components\TextInput::make('national_address.district')->label('الحي'),
                    \Filament\Forms\Components\TextInput::make('national_address.city')->label('المدينة'),
                    \Filament\Forms\Components\TextInput::make('national_address.postal_code')->label('الرمز البريدي')->regex('/^\d{5}$/'),
                    \Filament\Forms\Components\TextInput::make('national_address.additional_no')->label('الرقم الإضافي')->regex('/^\d{4}$/'),
                ])->columns(3)->columnSpanFull(),
            ])->columns(2)->columnSpanFull();
    }

    /** Key → Arabic label for the details stored with approvals and reconciliation issues. */
    public const DETAIL_KEYS = [
        'invoice_id' => 'الفاتورة', 'payment_id' => 'الدفعة', 'owner_id' => 'المالك', 'lease_id' => 'العقد', 'reason' => 'السبب',
        'amount' => 'المبلغ', 'amount_minor' => 'المبلغ', 'due_on' => 'الاستحقاق', 'number' => 'الرقم', 'invoice' => 'مبلغ الفاتورة',
        'schedule' => 'مبلغ الجدول', 'receipt' => 'السند', 'unallocated' => 'غير المخصص', 'ejar' => 'الحالة في إيجار', 'ours' => 'الحالة عندنا',
    ];

    /**
     * Stored details as one readable Arabic line: «المالك: عبدالله الشهري، المبلغ: 1,500.00». Ids of owners, invoices,
     * payments and leases are shown by name or number; *_minor amounts as money. Unknown keys keep their name.
     */
    public static function details(?array $d): string
    {
        if (! $d) {
            return '—';
        }
        $out = [];
        foreach ($d as $k => $v) {
            $val = match ($k) {
                'owner_id' => \App\Models\Owner::find($v)?->name_ar ?? '#' . $v,
                'invoice_id' => \App\Models\Invoice::find($v)?->number ?? '#' . $v,
                'payment_id' => \App\Models\Payment::find($v)?->receipt_number ?? '#' . $v,
                'lease_id' => ($l = \App\Models\Lease::with('unit')->find($v)) ? 'الوحدة ' . $l->unit?->code . ($l->ejar_contract_number ? ' (' . $l->ejar_contract_number . ')' : '') : '#' . $v,
                'amount_minor' => \App\Support\Dates\NumberFormatter::money((int) $v),
                'ejar', 'ours' => \App\Filament\Manager\Resources\Leases\LeaseResource::STATUS_AR[$v] ?? $v,
                default => is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE),
            };
            $out[] = (self::DETAIL_KEYS[$k] ?? str_replace('_', ' ', (string) $k)) . ': ' . $val;
        }
        return implode('، ', $out);
    }

    public static function moneyColumn(string $name, string $label): TextColumn
    {
        return TextColumn::make($name)->label($label)
            ->formatStateUsing(fn ($state) => NumberFormatter::money($state instanceof Money ? $state : ($state === null ? null : (int) $state)));
    }

    /** Archive (never delete) — hidden once archived. */
    public static function archive(): Action
    {
        return Action::make('archive')->label('أرشفة')->color('danger')->requiresConfirmation()
            ->visible(fn (Model $record) => $record->archived_at === null)
            ->action(fn (Model $record) => $record->archive());
    }

    /** Run a service call and show its domain error as a notification instead of a crash. */
    public static function attempt(callable $fn, string $success = 'تم'): mixed
    {
        try {
            $result = $fn();
            if ($success !== '') {   // '' = quiet (e.g. a report refreshed as the user changes a filter)
                Notification::make()->title($success)->success()->send();
            }
            return $result;
        } catch (Throwable $e) {
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                throw $e;
            }
            Notification::make()->title('تعذّر التنفيذ')->body($e->getMessage())->danger()->persistent()->send();
            return null;
        }
    }
}
