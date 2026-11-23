<?php

namespace App\Settings;

use App\Audit\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Resolves effective settings by inheritance with locking (REQ-0321, REQ-0374, REQ-0493):
 * user → property → subscriber → platform → schema default. Effective values are cached through
 * Laravel's cache store (database in development, Redis in production — REQ-0538).
 * Every subscriber-level change is written to the audit log (REQ-0376).
 */
class Settings
{
    public function __construct(private TenantContext $tenant)
    {
    }

    /** Effective value of a key for the current subscriber, an optional user and an optional property. */
    public function get(string $key, ?int $userId = null, ?int $propertyId = null): mixed
    {
        return $this->resolve($key, $userId, $propertyId)['value'];
    }

    /** Effective value plus where it came from and whether it is locked — for the settings UI. */
    public function resolve(string $key, ?int $userId = null, ?int $propertyId = null): array
    {
        $def = SettingsRegistry::get($key);
        $sid = $this->tenant->subscriberId();
        $cacheKey = sprintf('settings:%s:%s:%s:%s:%s', $this->version($sid), $sid ?? 'p', $userId ?? '-', $propertyId ?? '-', $key);

        return Cache::remember($cacheKey, 3600, function () use ($key, $def, $sid, $userId, $propertyId) {
            $result = ['value' => $def['default'], 'source' => 'default', 'locked' => false, 'pending' => $def['pending'] ?? null];

            // The platform level sets the defaults for every new subscriber (REQ-0323), for any key.
            $row = DB::table('platform_settings')->where('key', $key)->first();
            if ($row) {
                $result = ['value' => json_decode($row->value, true), 'source' => 'platform', 'locked' => false, 'pending' => null];
            }

            $subRow = null;
            if ($sid !== null && in_array('subscriber', $def['levels'], true)) {
                $subRow = DB::table('subscriber_settings')->where('key', $key)->first();   // RLS limits to current subscriber
                if ($subRow && $subRow->value !== null) {
                    $result = ['value' => json_decode($subRow->value, true), 'source' => 'subscriber', 'locked' => false, 'pending' => null];
                }
                if ($subRow && $subRow->locked) {
                    $result['locked'] = true;
                    return $result;                 // a locked key is never overridden below the subscriber
                }
            }

            if ($propertyId !== null && $sid !== null && in_array('property', $def['levels'], true)) {
                $row = DB::table('property_settings')->where('property_id', $propertyId)->where('key', $key)->first();
                if ($row) {
                    $result = ['value' => json_decode($row->value, true), 'source' => 'property', 'locked' => false, 'pending' => null];
                }
            }

            if ($userId !== null && in_array('user', $def['levels'], true)) {
                $row = DB::table('user_settings')->where('user_id', $userId)->where('key', $key)->first();
                if ($row) {
                    $result = ['value' => json_decode($row->value, true), 'source' => 'user', 'locked' => false, 'pending' => null];
                }
            }

            return $result;
        });
    }

    public function setPlatform(string $key, mixed $value): void
    {
        // Any key may receive a platform-wide default for new subscribers (REQ-0323).
        $value = SettingsRegistry::validate($key, $value);
        $before = DB::table('platform_settings')->where('key', $key)->value('value');
        DB::table('platform_settings')->updateOrInsert(['key' => $key], ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()]);
        $this->tenant->run(null, fn () => AuditLogger::record('setting.changed', 'platform_setting', ['value' => $before === null ? null : json_decode($before, true)], ['value' => $value], $key));
        $this->bumpAll();
    }

    /** Set (and optionally lock) a subscriber-level value. Pass $value = null with $locked = true to lock the inherited value. */
    public function setSubscriber(string $key, mixed $value, ?bool $locked = null): void
    {
        $sid = $this->requireSubscriber();
        $def = $this->assertLevel($key, 'subscriber');
        $value = SettingsRegistry::validate($key, $value);
        $existing = DB::table('subscriber_settings')->where('key', $key)->first();
        $locked ??= (bool) ($existing->locked ?? false);
        DB::table('subscriber_settings')->updateOrInsert(
            ['subscriber_id' => $sid, 'key' => $key],
            ['value' => $value === null ? null : json_encode($value), 'locked' => $locked, 'updated_at' => now(), 'created_at' => $existing->created_at ?? now()],
        );
        AuditLogger::record('setting.changed', 'subscriber_setting',
            $existing ? ['value' => $existing->value === null ? null : json_decode($existing->value, true), 'locked' => (bool) $existing->locked] : null,
            ['value' => $value, 'locked' => $locked], $key);
        $this->bump($sid);
    }

    public function setProperty(int $propertyId, string $key, mixed $value): void
    {
        $sid = $this->requireSubscriber();
        $this->assertLevel($key, 'property');
        $this->assertNotLocked($key);
        $value = SettingsRegistry::validate($key, $value);
        $existing = DB::table('property_settings')->where('property_id', $propertyId)->where('key', $key)->first();
        DB::table('property_settings')->updateOrInsert(
            ['subscriber_id' => $sid, 'property_id' => $propertyId, 'key' => $key],
            ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => $existing->created_at ?? now()],
        );
        AuditLogger::record('setting.changed', 'property_setting', $existing ? ['value' => json_decode($existing->value, true)] : null, ['value' => $value], "$propertyId:$key");
        $this->bump($sid);
    }

    public function setUser(int $userId, string $key, mixed $value): void
    {
        $this->assertLevel($key, 'user');
        $this->assertNotLocked($key);
        $value = SettingsRegistry::validate($key, $value);
        if ($value === null) {
            DB::table('user_settings')->where('user_id', $userId)->where('key', $key)->delete();
        } else {
            DB::table('user_settings')->updateOrInsert(
                ['user_id' => $userId, 'key' => $key],
                ['subscriber_id' => $this->tenant->subscriberId(), 'value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()],
            );
        }
        $this->bump($this->tenant->subscriberId());
    }

    private function assertLevel(string $key, string $level): array
    {
        $def = SettingsRegistry::get($key);
        if (! in_array($level, $def['levels'], true)) {
            throw new InvalidSettingValueException("Setting [$key] cannot be set at the $level level.");
        }
        return $def;
    }

    private function assertNotLocked(string $key): void
    {
        if ($this->tenant->hasSubscriber() && DB::table('subscriber_settings')->where('key', $key)->where('locked', true)->exists()) {
            throw new SettingLockedException("Setting [$key] is locked by the subscriber administrator.");
        }
    }

    private function requireSubscriber(): int
    {
        return $this->tenant->subscriberId() ?? throw new \LogicException('No subscriber in context.');
    }

    private function version(?int $sid): int
    {
        return (int) Cache::get('settings:version:all', 0) * 1000003 + (int) Cache::get('settings:version:' . ($sid ?? 'p'), 0);
    }

    private function bump(?int $sid): void
    {
        $k = 'settings:version:' . ($sid ?? 'p');
        Cache::forever($k, (int) Cache::get($k, 0) + 1);
    }

    private function bumpAll(): void
    {
        Cache::forever('settings:version:all', (int) Cache::get('settings:version:all', 0) + 1);
    }
}
