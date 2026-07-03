<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ST-Billing (S4) — idempotency ledger for Razorpay webhooks. A platform-level
 * table (not tenant-owned; webhooks are unauthenticated). String primary key =
 * the Razorpay event id.
 */
class WebhookEvent extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    protected $fillable = ['id', 'type'];
}
