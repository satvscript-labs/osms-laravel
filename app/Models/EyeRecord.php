<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EyeRecord extends Model
{
    use HasUuid, BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'customer_id', 'recorded_by', 'checked_by',
        // OD (right eye)
        'od_sph', 'od_cyl', 'od_axis', 'od_add', 'od_va', 'od_nv',
        // OS (left eye)
        'os_sph', 'os_cyl', 'os_axis', 'os_add', 'os_va', 'os_nv',
        'pd', 'notes',
    ];

    protected $casts = [
        // PRIV-03 — clinical free-text notes are encrypted at rest.
        'notes' => 'encrypted',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
