<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FT-WhatsApp — one outbound WhatsApp message (scheduled, sent, or a manual audit).
 *
 * See the migration for the full column story. The sweep command and the send
 * job run without an authenticated user, so they query with `withoutGlobalScopes()`
 * and scope by `tenant_id` explicitly (the TenantScope no-ops with no auth anyway).
 */
class WhatsAppMessage extends Model
{
    use HasUuid, BelongsToTenant;

    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'tenant_id', 'customer_id', 'order_id',
        'event', 'dedupe_key', 'channel', 'to_phone', 'template_name', 'payload',
        'status', 'scheduled_for', 'sent_at', 'provider_message_id',
        'delivery_status', 'error', 'attempts',
    ];

    protected $casts = [
        'payload' => 'array',
        'scheduled_for' => 'datetime',
        'sent_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Scheduled rows whose send time has arrived (the sweep's work list). */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where('status', 'scheduled')->where('scheduled_for', '<=', now());
    }
}
