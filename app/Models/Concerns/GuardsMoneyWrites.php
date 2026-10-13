<?php

namespace App\Models\Concerns;

use App\Services\MoneyWrite;
use App\Services\MoneyWriteRefused;

/**
 * E2.1 — money rows are written only through the engine and never deleted
 * (billing rules 2 and 6). Applied to the ledger and to charges.
 *
 * Model events see create / update / delete (and increment / decrement, which
 * fire `updating`). What they cannot see — query-builder mass writes,
 * DB::table(), the *Quietly() family — is banned in app/ by a static scan
 * (Phase85E2GuardTest), exactly as E1 does for subscriptions.
 *
 * In the test runner only, a write whose nearest non-framework caller is a file
 * under tests/ is a fixture and is allowed (as E1).
 */
trait GuardsMoneyWrites
{
    protected static function bootGuardsMoneyWrites(): void
    {
        static::creating(fn () => static::guardMoneyWrite('create'));
        static::updating(fn () => static::guardMoneyWrite('update'));
        static::deleting(fn () => static::guardMoneyWrite('delete'));
    }

    private static function guardMoneyWrite(string $what): void
    {
        $caller = static::moneyWriterFile();

        if (app()->runningUnitTests() && $caller !== null
            && str_starts_with($caller, str_replace('\\', '/', base_path('tests')) . '/')) {
            return;
        }

        // A money row is history: never deleted, not even by the engine.
        if ($what === 'delete') {
            throw new MoneyWriteRefused(sprintf(
                '%s rows are never deleted — reverse a payment or void a charge instead (attempted from %s).',
                class_basename(static::class),
                $caller ?? 'unknown code',
            ));
        }

        if (MoneyWrite::isOpen()) {
            return;
        }

        throw new MoneyWriteRefused(sprintf(
            'A %s row may only be written through the billing engine; a %s was attempted from %s.',
            class_basename(static::class),
            $what,
            $caller ?? 'unknown code',
        ));
    }

    /** The nearest file in the call stack that is neither the framework nor this model. */
    private static function moneyWriterFile(): ?string
    {
        $model = str_replace('\\', '/', (new \ReflectionClass(static::class))->getFileName());

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 80) as $frame) {
            $file = isset($frame['file']) ? str_replace('\\', '/', $frame['file']) : null;

            if ($file === null || str_contains($file, '/vendor/') || $file === $model
                || str_ends_with($file, '/app/Models/Concerns/GuardsMoneyWrites.php')) {
                continue;
            }

            return $file;
        }

        return null;
    }
}
