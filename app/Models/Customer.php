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
        'data_consent_at',
        'whatsapp_opt_in',
    ];

    protected $casts = [
        'age' => 'integer',
        'birthday' => 'date',
        'data_consent_at' => 'datetime',
        'whatsapp_opt_in' => 'boolean',
    ];

    /** PRIV-01 — whether the store has recorded this customer's data consent. */
    public function hasDataConsent(): bool
    {
        return $this->data_consent_at !== null;
    }

    /**
     * PRIV-02 — a known-minor customer (derived age under 18). DPDP forbids
     * behavioural marketing to children and requires guardian consent, so this
     * gates the birthday/marketing affordances. Unknown age → not treated as a
     * minor (we can't assert it), so nothing is over-suppressed.
     */
    public function isMinor(): bool
    {
        return $this->age !== null && $this->age < 18;
    }

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
    | Households (SHARE-01)
    |
    | A phone number identifies a handset, which a family shares — not a person.
    | There is no `households` table: the household IS "everyone on this number",
    | derived on demand. See _artifacts/SHARED_PHONE_DESIGN.md §3.1 for why a
    | table was considered and deliberately not added.
    |--------------------------------------------------------------------------
    */

    /** Everyone else in this store reachable on the same number. */
    public function householdMembers(): Builder
    {
        return static::query()
            ->where('phone', $this->phone)
            ->whereKeyNot($this->getKey())
            // A customer with no number is not in a household with every other
            // numberless customer.
            ->when($this->phone === null, fn ($q) => $q->whereRaw('1 = 0'));
    }

    /** Whether anyone else in this store shares this customer's number. */
    public function isPhoneShared(): bool
    {
        return $this->phone !== null && $this->householdMembers()->exists();
    }

    /** Scope to every customer on a given number (the household). */
    public function scopeSharingPhone(Builder $query, ?string $phone): Builder
    {
        return $phone === null
            ? $query->whereRaw('1 = 0')
            : $query->where('phone', $phone);
    }

    /**
     * Loose name equality, for deciding whether a name typed at the counter is
     * the SAME person already on this number or a relative.
     *
     * Deliberately forgiving about case, spacing and punctuation, and
     * deliberately NOT fuzzy beyond that: "Priya Shah" and "Priya" are treated
     * as different people, because on a shared household number they very often
     * are, and guessing wrong misfiles a prescription.
     */
    public static function sameName(?string $a, ?string $b): bool
    {
        $normalise = static fn (?string $v): string => preg_replace(
            '/\s+/', ' ',
            trim(mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', (string) $v) ?? ''))
        ) ?? '';

        $a = $normalise($a);

        return $a !== '' && $a === $normalise($b);
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
     * PRIV-02 — scope to customers who are adults by their birthday, i.e. born on
     * or before today-18-years. Used to keep minors out of the birthday-marketing
     * list. (Rows without a birthday are excluded; the birthday surfaces already
     * require one.)
     */
    public function scopeBornAdult(Builder $query): Builder
    {
        return $query->whereNotNull('birthday')
            ->whereDate('birthday', '<=', now()->subYears(18)->toDateString());
    }

    /**
     * Scope to customers whose birthday falls within the next `$days` days
     * (year-agnostic). Built from an explicit list of upcoming "MM-DD" keys, so a
     * year-end window (e.g. 28 Dec → 3 Jan) wraps naturally. DB-portable: uses
     * the connection's date-format function (SQLite locally/tests, MySQL in prod).
     */
    public function scopeUpcomingBirthday(Builder $query, int $days = 7): Builder
    {
        return $query->whereNotNull('birthday')
            ->whereIn(DB::raw(self::birthdayKeyExpression($query)), self::upcomingBirthdayKeys($days));
    }

    /**
     * WEB-02 — order by soonest upcoming birthday IN THE DATABASE, so pagination is
     * correct. Previously the page was sorted in PHP *after* pagination, which only
     * ordered the current 50 rows and put later pages out of sequence.
     *
     * Ordering by the raw "MM-DD" string would break across a year boundary
     * (28 Dec → 3 Jan sorts "01-03" first), so we rank by each key's position in the
     * already-wrapped window instead.
     */
    public function scopeOrderByUpcomingBirthday(Builder $query, int $days = 7): Builder
    {
        $keys = self::upcomingBirthdayKeys($days);
        $expr = self::birthdayKeyExpression($query);

        $cases = '';
        $bindings = [];
        foreach ($keys as $i => $key) {
            $cases .= " WHEN ? THEN {$i}";
            $bindings[] = $key;
        }

        return $query->orderByRaw("CASE {$expr}{$cases} ELSE 9999 END", $bindings);
    }

    /** Upcoming "MM-DD" keys from today through +$days, wrapping the year end. */
    private static function upcomingBirthdayKeys(int $days): array
    {
        return collect(range(0, $days))
            ->map(fn ($i) => now()->addDays($i)->format('m-d'))
            ->unique()->values()->all();
    }

    /** DB-portable expression yielding a birthday's "MM-DD" (SQLite vs MySQL). */
    private static function birthdayKeyExpression(Builder $query): string
    {
        return $query->getConnection()->getDriverName() === 'sqlite'
            ? "strftime('%m-%d', birthday)"
            : "DATE_FORMAT(birthday, '%m-%d')";
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
