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

    /**
     * E1.12 — who did this, in words. The engine records changes nobody in the
     * panel made with `meta.actor` (system / gateway / owner); those name the
     * actor rather than showing a dash, or whichever operator happened to be
     * signed in when a job ran.
     */
    public function actorLabel(): string
    {
        return match ($this->meta['actor'] ?? null) {
            'system' => 'System',
            'gateway' => 'Gateway (Razorpay)',
            'owner' => 'Owner' . ($this->admin_email ? ' · ' . $this->admin_email : ''),
            default => $this->admin_email ?? 'System',
        };
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
