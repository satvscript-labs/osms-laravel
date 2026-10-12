<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * P4 — the operator's knobs, changeable without a deploy.
 *
 * Deliberately NOT tenant-scoped: these are platform-wide, the operator's own
 * settings. Values are JSON-encoded so a knob can be a bool, a number or a
 * list without a migration each time.
 *
 * Reads are cached because `supervisedGlobally()` is consulted on every
 * self-serve billing request; writes bust the cache immediately, so "without a
 * deploy" also means "without waiting".
 */
class PlatformSetting extends Model
{
    protected $primaryKey = 'key';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    /** Keys in use. Named constants so a typo is a fatal, not a silent false. */
    public const SUPERVISED_MODE = 'supervised_mode';
    public const SELF_SERVE_ENABLED = 'self_serve_enabled';

    /**
     * P5 — the scheduler's heartbeat.
     *
     * On this host the scheduler is a cron entry the app cannot observe. Rather
     * than infer health from side effects ("mail went out, so cron must be
     * alive"), the scheduler stamps this every five minutes. A stale stamp is
     * then unambiguous: the cron is not running. Same reasoning as the backup
     * watchdog — check the observable outcome, not the intention.
     */
    public const SCHEDULER_HEARTBEAT = 'scheduler_heartbeat';

    private const CACHE_PREFIX = 'platform_setting:';

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember(
            self::CACHE_PREFIX . $key,
            now()->addMinutes(10),
            function () use ($key, $default) {
                $row = static::query()->find($key);

                if (! $row || $row->value === null) {
                    return $default;
                }

                $decoded = json_decode($row->value, true);

                return json_last_error() === JSON_ERROR_NONE ? $decoded : $row->value;
            },
        );
    }

    public static function set(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => json_encode($value)],
        );

        Cache::forget(self::CACHE_PREFIX . $key);
    }

    /**
     * Is the WHOLE platform in supervised mode?
     *
     * ⚠ Default false. Self-serve must keep working exactly as it does today
     * unless the operator deliberately turns it off (owner constraint C1) —
     * so a missing row, a failed migration, or an empty cache can never
     * accidentally take checkout offline.
     */
    public static function supervisedGlobally(): bool
    {
        return (bool) static::get(self::SUPERVISED_MODE, false);
    }
}
