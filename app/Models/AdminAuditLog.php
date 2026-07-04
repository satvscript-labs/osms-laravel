<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ST-Admin (S11) — append-only audit record of a superadmin action.
 *
 * Deliberately NOT tenant-scoped: this is the platform owner's own ledger.
 * There is no update/delete path anywhere in the app — records are only ever
 * created (via record()) and read.
 */
class AdminAuditLog extends Model
{
    use HasUuid;

    protected $fillable = [
        'admin_user_id', 'admin_email', 'action', 'tenant_id',
        'description', 'meta', 'ip_address',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    /**
     * Write an audit entry for the currently authenticated superadmin.
     * Called from every superadmin mutation.
     */
    public static function record(string $action, string $description, ?string $tenantId = null, array $meta = []): self
    {
        $user = auth()->user();

        return static::create([
            'admin_user_id' => $user?->id,
            'admin_email' => $user?->email,
            'action' => $action,
            'tenant_id' => $tenantId,
            'description' => $description,
            'meta' => $meta ?: null,
            'ip_address' => request()->ip(),
        ]);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
