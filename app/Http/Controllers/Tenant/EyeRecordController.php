<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEyeRecordRequest;
use App\Models\ActivityLog;
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

        $record = EyeRecord::create($data);

        ActivityLog::record('eye_record.created', "Added a prescription for {$customer->name}",
            'eye_record', $record->id, ['customer_id' => $customer->id]);

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

        ActivityLog::record('eye_record.updated', "Edited a prescription for {$record->customer->name}",
            'eye_record', $record->id, ['customer_id' => $record->customer_id]);

        return redirect()
            ->route('tenant.customers.show', $record->customer)
            ->with('status', 'Eye record updated.');
    }

    public function destroy(EyeRecord $record): RedirectResponse
    {
        $customer = $record->customer;

        ActivityLog::record('eye_record.deleted', "Deleted a prescription for {$customer->name}",
            'eye_record', $record->id, ['customer_id' => $customer->id]);

        $record->delete();

        return redirect()
            ->route('tenant.customers.show', $customer)
            ->with('status', 'Eye record deleted. You can recover it within 30 days.');
    }
}
