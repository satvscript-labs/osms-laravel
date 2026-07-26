<?php

namespace App\Models;

use App\Enums\MessagingMode;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Model;

/**
 * FT-WhatsApp — a store's WhatsApp messaging configuration (one row per tenant).
 *
 * A store with no row behaves as a fresh Manual store: use `defaultFor()` to get
 * a non-persisted instance carrying the zero-config defaults. All the messaging
 * decisions (which mode, what text, whether to show a pill) live here so views
 * and controllers ask one object.
 */
class WhatsAppConfig extends Model
{
    use HasUuid, BelongsToTenant;

    protected $table = 'whatsapp_configs';

    protected $fillable = [
        'tenant_id', 'mode',
        'on_placed', 'on_ready', 'on_delivered', 'on_birthday',
        'msg_placed', 'msg_ready', 'msg_delivered', 'msg_birthday',
        'provider', 'phone_number_id', 'waba_id', 'access_token', 'display_phone',
        'tpl_placed', 'tpl_ready', 'tpl_delivered', 'tpl_birthday', 'tpl_lang',
        'enabled', 'verified_at', 'needs_attention', 'default_country_code',
    ];

    protected $casts = [
        'on_placed' => 'boolean', 'on_ready' => 'boolean',
        'on_delivered' => 'boolean', 'on_birthday' => 'boolean',
        'enabled' => 'boolean', 'needs_attention' => 'boolean',
        'verified_at' => 'datetime',
        'access_token' => 'encrypted', // never stored/read in plaintext
    ];

    /** Defaults so a `new WhatsAppConfig` (zero-config store) resolves to Manual. */
    protected $attributes = [
        'mode' => 'manual',
        'on_placed' => true, 'on_ready' => true, 'on_delivered' => true, 'on_birthday' => true,
        'provider' => 'meta', 'tpl_lang' => 'en',
        'enabled' => false, 'needs_attention' => false,
        'default_country_code' => '+91',
    ];

    /** Order status → the message event appropriate for a card in that column. */
    public const STATUS_EVENT = [
        'pending' => 'order_placed',
        'ready_for_pickup' => 'order_ready',
        'delivered' => 'order_delivered',
    ];

    /** Button copy per event (manual pill). */
    public const PILL_LABEL = [
        'order_placed' => 'Send receipt',
        'order_ready' => 'Send “ready” message',
        'order_delivered' => 'Send thank-you',
        'birthday' => 'Send birthday wish',
    ];

    private const EVENT_MSG_COLUMN = [
        'order_placed' => 'msg_placed',
        'order_ready' => 'msg_ready',
        'order_delivered' => 'msg_delivered',
        'birthday' => 'msg_birthday',
    ];

    private const EVENT_TOGGLE = [
        'order_placed' => 'on_placed',
        'order_ready' => 'on_ready',
        'order_delivered' => 'on_delivered',
        'birthday' => 'on_birthday',
    ];

    private const EVENT_TPL_COLUMN = [
        'order_placed' => 'tpl_placed',
        'order_ready' => 'tpl_ready',
        'order_delivered' => 'tpl_delivered',
        'birthday' => 'tpl_birthday',
    ];

    /** A non-persisted default config for a store that hasn't configured WhatsApp. */
    public static function defaultFor(Tenant $tenant): self
    {
        $config = new self();
        $config->tenant_id = $tenant->id;
        $config->setRelation('tenant', $tenant);

        return $config;
    }

    public static function eventForStatus(string $status): ?string
    {
        return self::STATUS_EVENT[$status] ?? null;
    }

    public function eventEnabled(string $event): bool
    {
        $column = self::EVENT_TOGGLE[$event] ?? null;

        return $column !== null && (bool) $this->{$column};
    }

    /** Automated mode needs a phone number id + an access token to send. */
    public function hasCredentials(): bool
    {
        // The log driver sends nowhere, so it needs no credentials — this lets a
        // store exercise the full automated flow locally. Deliberately NOT allowed
        // in production: there, "no credentials" must never look like "connected",
        // or messages would silently vanish into a log file.
        if (config('whatsapp.driver') === 'log' && ! app()->environment('production')) {
            return true;
        }

        return filled($this->phone_number_id) && filled($this->access_token);
    }

    /** The approved template name for an event, falling back to the event key. */
    public function templateName(string $event): string
    {
        $column = self::EVENT_TPL_COLUMN[$event] ?? null;

        return $column && filled($this->{$column}) ? (string) $this->{$column} : $event;
    }

    /**
     * Whether the Cloud API path is actually usable right now.
     *
     * `automated_enabled` is a RUNTIME kill-switch, not just a settings guard: a
     * store whose row still says `automated` (set before the freeze, or carried in
     * from a non-production database) must degrade to the manual pill rather than
     * silently scheduling sends nobody receives.
     */
    public function isReady(): bool
    {
        return (bool) config('whatsapp.automated_enabled')
            && $this->enabled
            && $this->verified_at !== null
            && ! $this->needs_attention
            && $this->hasCredentials();
    }

    /** Resolve the effective mode for one event (see MessagingMode). */
    public function modeFor(string $event): MessagingMode
    {
        if (! $this->eventEnabled($event)) {
            return MessagingMode::Off;
        }

        return match ($this->mode) {
            'off' => MessagingMode::Off,
            'automated' => $this->isReady() ? MessagingMode::Automated : MessagingMode::ManualFallback,
            default => MessagingMode::Manual,
        };
    }

    /** The wording for an event — the store's custom copy, else the built-in default. */
    public function messageTemplate(string $event): string
    {
        $column = self::EVENT_MSG_COLUMN[$event] ?? null;
        $custom = $column ? trim((string) $this->{$column}) : '';

        return $custom !== ''
            ? $custom
            : (string) (config("whatsapp.default_messages.{$event}") ?? '');
    }

    /**
     * SHARE-01 — event keys whose message template does not name the customer.
     *
     * A phone number is a household handset. "Your order is ready" sent to a
     * number four people share tells nobody whose order it is; the recipient's
     * name is what makes it usable, and it is why every shipped default opens
     * with "Hi {name}". A store can still edit that out, so this surfaces it as
     * a warning in Settings rather than silently degrading their messages.
     *
     * @return list<string>
     */
    public function templatesMissingName(): array
    {
        $missing = [];

        foreach (array_keys(self::EVENT_MSG_COLUMN) as $event) {
            if (! str_contains($this->messageTemplate($event), '{name}')) {
                $missing[] = $event;
            }
        }

        return $missing;
    }

    /** Fill an event template with an order's details. */
    public function renderOrderMessage(Order $order, string $event): string
    {
        $customer = $order->customer;
        $store = $this->relationLoaded('tenant') ? $this->tenant : $order->tenant;

        return strtr($this->messageTemplate($event), [
            '{name}' => $customer?->name ?? 'there',
            '{store}' => $store?->store_name ?? 'our store',
            '{order}' => strtoupper(substr((string) $order->id, 0, 8)),
            '{total}' => '₹ ' . number_format((float) $order->total_amount, 2),
            '{balance}' => '₹ ' . number_format((float) $order->balance_due, 2),
            '{ready_date}' => $order->estimated_ready_at?->format('d M Y') ?? '',
        ]);
    }

    /**
     * The manual "Send on WhatsApp" pill for an order card, or null when nothing
     * should render (Off, no matching event, disabled event, no phone, or a
     * ready automated send that the ring flow owns instead).
     *
     * @return array{event:string,mode:MessagingMode,fallback:bool,url:string,label:string}|null
     */
    public function orderPill(Order $order): ?array
    {
        $event = self::eventForStatus((string) $order->status);
        if ($event === null) {
            return null;
        }

        $mode = $this->modeFor($event);
        if (! $mode->showsManualPill()) {
            return null; // Off, or Automated (owned by the scheduled-send/ring flow)
        }

        $digits = Phone::waDigits($order->customer?->phone, $this->default_country_code);
        if ($digits === null) {
            return null;
        }

        return [
            'event' => $event,
            'mode' => $mode,
            'fallback' => $mode->needsSetupHint(),
            'url' => 'https://wa.me/' . $digits . '?text=' . rawurlencode($this->renderOrderMessage($order, $event)),
            'label' => self::PILL_LABEL[$event] ?? 'Send on WhatsApp',
        ];
    }

    /**
     * A pre-filled wa.me link nudging a customer to settle an order's balance —
     * used by the analytics "Pending dues" surface. Staff-initiated (opened from
     * their own WhatsApp), so it's mode-independent: available whenever the
     * customer has a usable number. Null when the number can't be dialled.
     */
    public function duesReminderUrl(Order $order): ?string
    {
        $digits = Phone::waDigits($order->customer?->phone, $this->default_country_code);
        if ($digits === null) {
            return null;
        }

        return 'https://wa.me/' . $digits . '?text='
            . rawurlencode($this->renderOrderMessage($order, 'payment_due'));
    }
}
