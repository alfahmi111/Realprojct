<?php

namespace App\Filament\Support;

use App\Support\Dates\NumberFormatter;
use App\Support\Money\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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
