<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-15 — branches become billable, and a bargain becomes recordable.
 *
 * Three separate ideas, kept separate on purpose because conflating them is how
 * pricing systems rot:
 *
 *   1. **Volume tiers** (`plans.price_tiers`) — the LIST rate per branch, which
 *      may fall as branch count rises. Data, not code: the owner sets the
 *      breakpoints at runtime without a deploy.
 *
 *   2. **Negotiated price** (already exists) — a customer's standing bespoke
 *      rate. Owner decision 2026-08-14: it is **per branch** and multiplies.
 *
 *   3. **A one-off discount** (`subscription_invoices.list_amount` /
 *      `discount_amount` / `discount_reason`) — what somebody bargained off a
 *      SINGLE charge at the counter. It never persists and never repeats.
 *
 * ⚠ (3) is deliberately NOT an offer or coupon engine, which the owner ruled
 * out and which remains ruled out. There is no code, no rule, no stacking and
 * no expiry — just an amount and a reason, typed by an operator at the moment
 * money changes hands. Anything recurring is (2), which already exists.
 *
 * `amount` keeps its exact meaning — **what was actually received** — so every
 * revenue, lifetime-value and collection sum built on it stays correct without
 * being touched. `list_amount` records what it would have been, which is the
 * only way "what are we giving away at the counter?" stays answerable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            /*
             * Volume tiers, e.g.
             *   [{"from":1,"to":2,"monthly":499,"yearly":4990},
             *    {"from":3,"to":4,"monthly":450,"yearly":4500},
             *    {"from":5,"to":null,"monthly":400,"yearly":4000}]
             *
             * NULL means "no tiers" — every branch is charged monthly_price /
             * yearly_price. That is the state every existing row is in, so this
             * migration changes nobody's price.
             */
            $table->json('price_tiers')->nullable()->after('yearly_price');
        });

        Schema::table('subscription_invoices', function (Blueprint $table) {
            // What the charge would have been before anything was knocked off.
            // Null = no discount, i.e. list_amount === amount.
            $table->decimal('list_amount', 10, 2)->nullable()->after('amount');
            $table->decimal('discount_amount', 10, 2)->nullable()->after('list_amount');
            $table->string('discount_reason', 500)->nullable()->after('discount_amount');

            // A prorated charge must be able to reproduce its own arithmetic
            // months later, on a receipt, after the branch count has changed
            // again. Storing the working (not just the answer) is the only way
            // that survives — recomputing it later would use today's numbers.
            $table->json('calculation')->nullable()->after('discount_reason');
        });
    }

    public function down(): void
    {
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn('price_tiers'));

        Schema::table('subscription_invoices', function (Blueprint $table) {
            $table->dropColumn(['list_amount', 'discount_amount', 'discount_reason', 'calculation']);
        });
    }
};
