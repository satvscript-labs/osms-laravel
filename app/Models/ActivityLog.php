<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRIV-04 — an append-only, tenant-scoped record of a staff mutation. There is no
 * update/delete path in the app; rows are only ever created (via record()) and read.
 */
class ActivityLog extends Model
{
    use HasUuid, BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'user_id', 'user_name', 'action',
        'subject_type', 'subject_id', 'description', 'meta', 'ip_address',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    /**
     * Record an action for the currently authenticated tenant user. A no-op outside
     * a tenant session (e.g. an unauthenticated job) so it never throws in the wrong
     * context.
     */
    public static function record(
        string $action,
        string $description,
        ?string $subjectType = null,
        ?string $subjectId = null,
        array $meta = [],
    ): ?self {
        $user = auth()->user();

        if (! $user || ! $user->tenant_id) {
            return null;
        }

        return static::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'description' => $description,
            'meta' => $meta ?: null,
            'ip_address' => request()->ip(),
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
