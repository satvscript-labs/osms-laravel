<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        $search = trim((string) $request->query('q', ''));
        // "patients" = the derived role (≥1 eye record); "birthdays" = birthday in
        // the next 7 days. Anything else = all customers.
        $filter = in_array($request->query('filter'), ['patients', 'birthdays'], true)
            ? $request->query('filter')
            : '';

        $customers = Customer::query()
            ->withCount('eyeRecords') // powers the "Patient" badge without an N+1
            ->when($search !== '', fn ($query) => $query->searchBy(['name', 'phone'], $search))
            ->when($filter === 'patients', fn ($query) => $query->patients())
            // PRIV-02 — the birthdays view is a marketing-outreach list, so minors
            // are excluded (bornAdult) per DPDP's ban on marketing to children.
            ->when($filter === 'birthdays', fn ($query) => $query->upcomingBirthday(7)->bornAdult())
            // WEB-02 — the birthdays view sorts by soonest birthday in SQL (so
            // pagination is correct); every other view is newest-first.
            ->when(
                $filter === 'birthdays',
                fn ($query) => $query->orderByUpcomingBirthday(7),
                fn ($query) => $query->latest(),
            )
            ->paginate(50)
            ->withQueryString();

        // SHARE-01 — which numbers ON THIS PAGE are shared by more than one
        // person. One grouped query over just the page's numbers, rather than an
        // exists() per row.
        $sharedPhones = $this->sharedPhonesAmong($customers->getCollection()->pluck('phone'));

        // Live search/filter (fetched by Alpine) — return lightweight JSON rows.
        if ($request->wantsJson()) {
            return response()->json([
                'customers' => $customers->getCollection()->map(fn (Customer $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'phone' => $c->phone,
                    'age' => $c->age,
                    'gender' => $c->gender,
                    'is_patient' => $c->eye_records_count > 0,
                    'shares_number' => $c->phone !== null && isset($sharedPhones[$c->phone]),
                    // PRIV-02 — suppress the birthday nudge/chip for minors.
                    'days_until_birthday' => $c->isMinor() ? null : $c->daysUntilBirthday(),
                    'added' => $c->created_at->format('d M Y'),
                    'url' => route('tenant.customers.show', $c),
                ])->values(),
                'total' => $customers->total(),
                'has_more' => $customers->hasMorePages(),
            ]);
        }

        return view('tenant.customers.index', compact('customers', 'search', 'filter', 'sharedPhones'));
    }

    /**
     * SHARE-01 — of the given numbers, which are held by more than one customer.
     *
     * @param  \Illuminate\Support\Collection<int,string|null>  $phones
     * @return array<string,int> phone => how many people hold it
     */
    private function sharedPhonesAmong($phones): array
    {
        $phones = $phones->filter()->unique()->values();

        if ($phones->isEmpty()) {
            return [];
        }

        return Customer::query()
            ->whereIn('phone', $phones)
            ->groupBy('phone')
            ->havingRaw('COUNT(*) > 1')
            ->pluck(\DB::raw('COUNT(*)'), 'phone')
            ->all();
    }

    /**
     * SHARE-01 — who else in this store is already on a given number.
     *
     * A phone number is a household handset, so before creating a customer the
     * UI asks this and offers an explicit choice: pick one of these people, or
     * add a new person on the same number. Powers the chooser on BOTH creation
     * surfaces — the customer form and the order builder's inline add.
     *
     * Read-only and tenant-scoped by the global scope.
     */
    public function byPhone(Request $request): JsonResponse
    {
        // Accept whatever the field currently holds — the caller is typing, so
        // it may be partial or unformatted — and normalise the same way the
        // form request does, so a match here means a match on submit.
        $national = preg_replace('/\D/', '', (string) $request->query('phone', '')) ?? '';
        $code = trim((string) ($request->query('country_code') ?: '+91'));

        // Only a complete number identifies a household. Anything shorter would
        // spray half-matches at the user while they are still typing.
        if (strlen($national) !== 10) {
            return response()->json(['phone' => null, 'customers' => []]);
        }

        $phone = $code . ' ' . $national;

        $customers = Customer::query()
            ->sharingPhone($phone)
            ->withCount('eyeRecords')
            // Exclude the record being edited — a customer never shares a number
            // with themselves.
            ->when($request->query('except'), fn ($q, $id) => $q->whereKeyNot($id))
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return response()->json([
            'phone' => $phone,
            'customers' => $customers->map(fn (Customer $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'is_patient' => $c->eye_records_count > 0,
                'eye_records' => $c->eye_records_count,
                'age' => $c->age,
                'gender' => $c->gender,
                'added' => $c->created_at->format('d M Y'),
                'url' => route('tenant.customers.show', $c),
            ])->values(),
        ]);
    }

    /**
     * SHARE-01 — merge a duplicate profile into this one. **Frozen.**
     *
     * Gated SERVER-SIDE, not merely hidden in the dropdown: merging re-points
     * orders, payments and prescriptions and then discards a profile, so it must
     * not be reachable by typing the URL while it is still "Coming soon".
     * Mirrors `config('whatsapp.automated_enabled')`.
     */
    public function merge(Customer $customer): View
    {
        abort_unless(config('customers.merge_enabled'), 404);

        // Likely duplicates: same household, or a near-identical name. Ranked so
        // the most plausible candidate is first.
        $candidates = Customer::query()
            ->whereKeyNot($customer->id)
            ->where(fn ($q) => $q
                ->when($customer->phone, fn ($p) => $p->orWhere('phone', $customer->phone))
                ->orWhere('name', 'like', '%' . $customer->name . '%'))
            ->withCount(['eyeRecords', 'orders'])
            ->limit(20)
            ->get();

        return view('tenant.customers.merge', compact('customer', 'candidates'));
    }

    public function create(): View
    {
        return view('tenant.customers.create');
    }

    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        $customer = Customer::create($this->withConsent($request));

        return redirect()
            ->route('tenant.customers.show', $customer)
            ->with('status', 'Customer added.');
    }

    /**
     * PRIV-01 — fold the two consent checkboxes into the DB shape. `data_consent`
     * (a non-column checkbox) becomes a `data_consent_at` timestamp; an existing
     * consent date is preserved so re-saving never rewrites when consent was given.
     */
    private function withConsent(StoreCustomerRequest $request, ?Customer $existing = null): array
    {
        $data = $request->validated();
        unset($data['data_consent']); // not a column

        $data['whatsapp_opt_in'] = $request->boolean('whatsapp_opt_in');
        $data['data_consent_at'] = $request->boolean('data_consent')
            ? ($existing?->data_consent_at ?? now())
            : null;

        return $data;
    }

    public function edit(Customer $customer): View
    {
        return view('tenant.customers.edit', compact('customer'));
    }

    public function update(StoreCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $customer->update($this->withConsent($request, $customer));

        return redirect()
            ->route('tenant.customers.show', $customer)
            ->with('status', 'Customer updated.');
    }

    /** FG-Delete — archived (soft-deleted) customers, restorable for 30 days. */
    public function trash(Request $request): View
    {
        // UX-04 — live archive search. Filtering happens in SQL (not on the rendered
        // page) so it searches the whole archive rather than just the current page.
        $search = trim((string) $request->query('search', ''));

        $customers = Customer::onlyTrashed()
            ->when($search !== '', fn ($query) => $query->searchBy(['name', 'phone'], $search))
            ->latest('deleted_at')
            ->paginate(50)
            ->withQueryString();

        // The live search swaps in this rendered fragment (rows keep their
        // CSRF-protected restore/delete forms — see partials/trash-list-script).
        if ($request->ajax()) {
            return view('tenant.customers.partials.trash-rows', compact('customers', 'search'));
        }

        return view('tenant.customers.trash', compact('customers', 'search'));
    }

    /**
     * FG-Delete — archive a customer (soft delete). Blocked while the customer
     * has order history, so a receipt can never be orphaned; archive junk rows.
     */
    public function destroy(Customer $customer): RedirectResponse
    {
        if ($customer->orders()->exists()) {
            return back()->with('error', 'This customer has order history and cannot be archived.');
        }

        $customer->delete();

        return redirect()
            ->route('tenant.customers.index')
            ->with('status', 'Customer archived. You can restore it within 30 days.');
    }

    /** FG-Delete — restore an archived customer. */
    public function restore(Customer $customer): RedirectResponse
    {
        $customer->restore();

        return redirect()
            ->route('tenant.customers.show', $customer)
            ->with('status', 'Customer restored.');
    }

    /** FG-Delete — permanently delete an archived customer (irreversible). */
    public function forceDelete(Customer $customer): RedirectResponse
    {
        \App\Models\ActivityLog::record('customer.deleted', "Permanently deleted customer {$customer->name}",
            'customer', $customer->id, ['phone' => $customer->phone]);

        $customer->forceDelete();

        return redirect()
            ->route('tenant.customers.trash')
            ->with('status', 'Customer permanently deleted.');
    }

    public function show(Customer $customer): View
    {
        $customer->load([
            'eyeRecords',
            'orders',
        ]);

        // Merge eye records + orders into one timeline, newest first.
        $timeline = $customer->eyeRecords->map(fn ($r) => [
            'kind' => 'rx',
            'at' => $r->created_at,
            'data' => $r,
        ])->concat($customer->orders->map(fn ($o) => [
            'kind' => 'order',
            'at' => $o->created_at,
            'data' => $o,
        ]))->sortByDesc('at')->values();

        // SHARE-01 — everyone else on this number. Shown so staff know a message
        // or a call to it may reach a relative, and so a mis-attached record is
        // easy to spot and move.
        $household = $customer->householdMembers()->withCount('eyeRecords')->latest()->get();

        return view('tenant.customers.show', compact('customer', 'timeline', 'household'));
    }
}
