<?php

namespace App\Filament\Platform\Resources\Subscribers\Pages;

use App\Filament\Platform\Resources\Subscribers\SubscriberResource;
use App\Models\Subscriber;
use App\Models\User;
use App\Tenancy\TenantContext;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateSubscriber extends CreateRecord
{
    protected static string $resource = SubscriberResource::class;

    /** New subscriber + its first manager, created inside the new subscriber's context (RLS WITH CHECK). */
    protected function handleRecordCreation(array $data): Model
    {
        $ctx = app(TenantContext::class);
        return DB::transaction(function () use ($data, $ctx) {
            $s = Subscriber::create(collect($data)->only(['name_ar', 'name_en', 'plan', 'status'])->all());
            $ctx->run($s->id, fn () => User::create(['subscriber_id' => $s->id, 'name' => $data['manager_name'], 'email' => $data['manager_email'], 'password' => $data['manager_password'], 'role' => 'manager']));
            return $s;
        });
    }
}
