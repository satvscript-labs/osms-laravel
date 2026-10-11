<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\WhatsAppConfig;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AnalyticsController extends Controller
{
    /** Hard ceiling for "show all" lists and exports so wide ranges stay bounded. */
    private const MAX_ROWS = 5000;

    /** Resolve the [from, to] range from the request (default: last 30 days). */
    private function range(Request $request): array
    {
        $to = $this->parseDate($request->query('to')) ?? now();
        $from = $this->parseDate($request->query('from')) ?? now()->subDays(30);

        return [$from->startOfDay(), $to->endOfDay()];
    }

    private function parseDate(?string $s): ?Carbon
    {
        if (! $s) {
            return null;
        }
        try {
            return Carbon::parse($s);
        } catch (\Exception) {
            return null;
        }
    }

    public function index(Request $request): View
    {
        [$from, $to] = $this->range($request);

        // Delivered orders in range → revenue, COGS, profit, top brands.
        // Bucketed by created_at (order/placement date) — NOT updated_at, which
        // drifts every time the order is saved (a later payment, edit, or status
        // change would otherwise move the sale into the wrong day). This keeps
        // revenue on the same date as the orders list "Placed" column and the ledger.
        // PERF-02 — never hydrate a whole date range at once. The headline money is a
        // single SQL aggregate; the per-line math below streams in chunks so memory
        // stays flat no matter how wide the range is.
        $deliveredQuery = Order::where('status', 'delivered')
            ->whereBetween('created_at', [$from, $to]);

        // Revenue is net of discount (total_amount already = subtotal − discount).
        $totals = (clone $deliveredQuery)
            ->selectRaw('COUNT(*) as orders_count, COALESCE(SUM(total_amount), 0) as revenue, COALESCE(SUM(discount_amount), 0) as discounts')
            ->first();

        $revenue = (float) $totals->revenue;
        $discounts = (float) $totals->discounts;
        $ordersCount = (int) $totals->orders_count;

        // Single pass over delivered lines. The order-level discount is allocated
        // pro-rata to each line (factor = net total ÷ gross subtotal) so brand
        // revenue sums back to net total revenue (D7). COGS/margin count only lines
        // whose item has a *recorded* cost — a `cost_price` of 0 means "not entered"
        // (many owners skip it), so it's excluded rather than faking a 100% margin
        // (5.3). Revenue always counts the full sale.
        $cogs = 0.0;
        $costedRevenue = 0.0; // net revenue attributable to lines that have a cost
        $uncostedLines = 0;
        $brandMap = [];
        (clone $deliveredQuery)
            ->with('items.inventory:id,cost_price,brand')
            ->chunkById(200, function ($orders) use (&$cogs, &$costedRevenue, &$uncostedLines, &$brandMap) {
                foreach ($orders as $o) {
                    $sub = (float) $o->subtotal;
                    $factor = $sub > 0 ? (float) $o->total_amount / $sub : 1.0;
                    foreach ($o->items as $it) {
                        $lineNet = (float) $it->unit_price * $it->quantity * $factor;
                        $cost = (float) ($it->inventory->cost_price ?? 0);
                        if ($cost > 0) {
                            $cogs += $cost * $it->quantity;
                            $costedRevenue += $lineNet;
                        } else {
                            $uncostedLines++;
                        }

                        // A local/custom line (6.4) has no inventory — bucket it distinctly so
                        // it reads clearly instead of colliding with a real item whose brand is
                        // just unset ("—"). Its cost is 0 above, so it's already excluded from
                        // COGS/margin while its revenue still counts (5.3's rule, for free).
                        $brand = $it->inventory
                            ? (trim((string) $it->inventory->brand) ?: '—')
                            : 'Local / custom item';
                        $brandMap[$brand] ??= ['quantity' => 0, 'revenue' => 0.0];
                        $brandMap[$brand]['quantity'] += $it->quantity;
                        $brandMap[$brand]['revenue'] += $lineNet;
                    }
                }
            });

        // Profit + margin are computed over costed lines only (uncosted lines can't
        // contribute a known profit). Margin is null when there's no cost data → "—".
        $profit = $costedRevenue - $cogs;
        $margin = $costedRevenue > 0 ? ($profit / $costedRevenue) * 100 : null;
        // $ordersCount already came from the SQL aggregate above (PERF-02).

        $topBrands = collect($brandMap)
            ->map(fn ($v, $brand) => ['brand' => $brand] + $v)
            ->sortByDesc('revenue')->take(10)->values();

        // Ledger — orders in range (default 50; "show all" raises the cap but never removes it).
        $showAllLedger = $request->boolean('ledger_all');
        $ledger = Order::with('customer:id,name')
            ->whereBetween('created_at', [$from, $to])
            ->latest()
            ->limit($showAllLedger ? self::MAX_ROWS : 50)
            ->get();

        // Pending dues — outstanding balances (default 50; "show all" raises the cap but never removes it).
        $showAllDues = $request->boolean('dues_all');
        $dues = Order::with('customer:id,name,phone')
            ->where('status', '!=', 'cancelled')
            ->where('balance_due', '>', 0)
            ->orderByDesc('balance_due')
            ->limit($showAllDues ? self::MAX_ROWS : 50)
            ->get();

        // 5.4 — money actually collected in range, grouped by payment method, so the
        // owner can reconcile the cash drawer + each digital channel. Sourced from the
        // payments ledger (when money was received), NOT order totals — the two
        // legitimately differ (advances on open orders in; unpaid balances out).
        $paid = Payment::whereBetween('created_at', [$from, $to])
            ->selectRaw('method, SUM(amount) as total')
            ->groupBy('method')
            ->pluck('total', 'method');

        $collectedByMethod = collect(['cash', 'card', 'upi', 'other'])
            ->mapWithKeys(fn ($m) => [$m => (float) ($paid[$m] ?? 0)]);
        $collectedTotal = (float) $collectedByMethod->sum();

        $isStoreAdmin = $request->user()->isStoreAdmin() || $request->user()->isSuperadmin();
        $stats = [
            'revenue' => $revenue,
            'discounts' => $discounts,
            'ordersCount' => $ordersCount,
            'uncostedLines' => $uncostedLines,
            'cogs' => $isStoreAdmin ? $cogs : null,
            'profit' => $isStoreAdmin ? $profit : null,
            'margin' => $isStoreAdmin ? $margin : null,
        ];
        $fromStr = $from->format('Y-m-d');
        $toStr = $to->format('Y-m-d');

        // FT-WhatsApp — resolve the store's config (or a Manual default) so each
        // pending-due row can offer a pre-filled "settle your balance" reminder.
        $tenant = $request->user()->tenant;
        $waConfig = $tenant->whatsappConfig ?? WhatsAppConfig::defaultFor($tenant);

        return view('tenant.analytics.index', compact(
            'stats', 'topBrands', 'ledger', 'dues', 'fromStr', 'toStr', 'showAllLedger', 'showAllDues',
            'collectedByMethod', 'collectedTotal', 'waConfig',
        ));
    }

}
