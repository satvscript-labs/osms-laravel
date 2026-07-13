<?php

namespace App\Http\Controllers\Tenant;

use App\Exports\CustomersExport;
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
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when($filter === 'patients', fn ($query) => $query->patients())
            ->when($filter === 'birthdays', fn ($query) => $query->upcomingBirthday(7))
            ->latest()
            ->paginate(50)
            ->withQueryString();

        // Birthdays view: order the page by soonest upcoming birthday (small set).
        if ($filter === 'birthdays') {
            $customers->setCollection(
                $customers->getCollection()->sortBy(fn (Customer $c) => $c->daysUntilBirthday())->values()
            );
        }

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
                    'days_until_birthday' => $c->daysUntilBirthday(),
                    'added' => $c->created_at->format('d M Y'),
                    'url' => route('tenant.customers.show', $c),
                ])->values(),
                'total' => $customers->total(),
                'has_more' => $customers->hasMorePages(),
            ]);
        }

        return view('tenant.customers.index', compact('customers', 'search', 'filter'));
    }

    public function create(): View
    {
        return view('tenant.customers.create');
    }

    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        $customer = Customer::create($request->validated());

        return redirect()
            ->route('tenant.customers.show', $customer)
            ->with('status', 'Customer added.');
    }

    public function edit(Customer $customer): View
    {
        return view('tenant.customers.edit', compact('customer'));
    }

    public function update(StoreCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $customer->update($request->validated());

        return redirect()
            ->route('tenant.customers.show', $customer)
            ->with('status', 'Customer updated.');
    }

    /** FG-Delete — archived (soft-deleted) customers, restorable for 30 days. */
    public function trash(): View
    {
        $customers = Customer::onlyTrashed()
            ->latest('deleted_at')
            ->paginate(50);

        return view('tenant.customers.trash', compact('customers'));
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

        return view('tenant.customers.show', compact('customer', 'timeline'));
    }
}
