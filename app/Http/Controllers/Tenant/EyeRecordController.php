<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEyeRecordRequest;
use App\Models\Customer;
use App\Models\EyeRecord;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class EyeRecordController extends Controller
{
    public function create(Customer $customer): View
    {
        return view('tenant.eye-records.create', compact('customer'));
    }

    public function store(StoreEyeRecordRequest $request, Customer $customer): RedirectResponse
    {
        $data = $request->validated();
        $data['customer_id'] = $customer->id;
        $data['recorded_by'] = $request->user()->id;
        // 5.2.3 — default the examiner to the staff member when left blank.
        $data['checked_by'] = ($data['checked_by'] ?? '') ?: $request->user()->name;

        EyeRecord::create($data);

        return redirect()
            ->route('tenant.customers.show', $customer)
            ->with('status', 'Eye record saved.');
    }

    public function edit(EyeRecord $record): View
    {
        $customer = $record->customer;

        return view('tenant.eye-records.edit', compact('customer', 'record'));
    }

    public function update(StoreEyeRecordRequest $request, EyeRecord $record): RedirectResponse
    {
        $data = $request->validated();
        $data['checked_by'] = ($data['checked_by'] ?? '') ?: ($record->checked_by ?: $request->user()->name);
        $record->update($data);

        return redirect()
            ->route('tenant.customers.show', $record->customer)
            ->with('status', 'Eye record updated.');
    }

    public function destroy(EyeRecord $record): RedirectResponse
    {
        $customer = $record->customer;
        $record->delete();

        return redirect()
            ->route('tenant.customers.show', $customer)
            ->with('status', 'Eye record deleted.');
    }
}
