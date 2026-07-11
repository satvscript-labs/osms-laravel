<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * A store contact. Every buyer is a customer; a customer becomes a "patient"
 * (a derived role, not a separate table) once they have at least one eye record.
 */
class Customer extends Model
{
    use HasUuid, BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'phone',
        'age',
        'birthday',
        'gender',
    ];

    protected $casts = [
        'age' => 'integer',
        'birthday' => 'date',
    ];

    /**
     * Age is derived from the birthday when one is on file (always current);
     * otherwise it falls back to the manually-entered `age` column (5.5).
     */
    public function getAgeAttribute($value): ?int
    {
        if (! empty($this->attributes['birthday'])) {
            return (int) $this->birthday->age;
        }

        return $value !== null ? (int) $value : null;
    }

    public function eyeRecords(): HasMany
    {
        return $this->hasMany(EyeRecord::class)->latest();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class)->latest();
    }

    /** A customer is a "patient" once they have a prescription on file. */
    public function isPatient(): bool
    {
        return $this->eyeRecords()->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Contact links (FT-WhatsApp req 2) — always manual, staff-device initiated.
    | These open a chat/dialer from the staff member's own phone and are wholly
    | independent of the store's automated-messaging mode.
    |--------------------------------------------------------------------------
    */

    /** A wa.me chat link with this customer, or null if the number isn't usable. */
    public function whatsappUrl(): ?string
    {
        $digits = Phone::waDigits($this->phone);

        return $digits ? 'https://wa.me/' . $digits : null;
    }

    /** A `tel:` dialer href for this customer, or null if the number isn't usable. */
    public function telHref(): ?string
    {
        $e164 = Phone::e164($this->phone);

        return $e164 ? 'tel:' . $e164 : null;
    }

    /** Scope to customers who have at least one eye record (the "patients" view). */
    public function scopePatients(Builder $query): Builder
    {
        return $query->whereHas('eyeRecords');
    }

    /**
     * Scope to customers whose birthday falls within the next `$days` days
     * (year-agnostic). Built from an explicit list of upcoming "MM-DD" keys, so a
     * year-end window (e.g. 28 Dec → 3 Jan) wraps naturally. DB-portable: uses
     * the connection's date-format function (SQLite locally/tests, MySQL in prod).
     */
    public function scopeUpcomingBirthday(Builder $query, int $days = 7): Builder
    {
        $keys = collect(range(0, $days))
            ->map(fn ($i) => now()->addDays($i)->format('m-d'))
            ->unique()->values()->all();

        $expr = $query->getConnection()->getDriverName() === 'sqlite'
            ? "strftime('%m-%d', birthday)"
            : "DATE_FORMAT(birthday, '%m-%d')";

        return $query->whereNotNull('birthday')->whereIn(DB::raw($expr), $keys);
    }

    /**
     * Whole days until this customer's next birthday (today = 0), or null when no
     * birthday is on file. Rolls to next year once this year's date has passed.
     */
    public function daysUntilBirthday(): ?int
    {
        if (empty($this->attributes['birthday'])) {
            return null;
        }

        $today = now()->startOfDay();
        $next = $this->birthday->copy()->year($today->year)->startOfDay();
        if ($next->lt($today)) {
            $next->addYear();
        }

        return (int) $today->diffInDays($next);
    }
}
