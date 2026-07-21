{{-- UX-04 — archive table body, swapped in live by the search box. Kept as a
     server-rendered partial because each row carries CSRF-protected forms. --}}
@if ($items->isNotEmpty())
    <div class="card border-0 shadow-sm rounded-4 stagger">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="text-muted-foreground text-uppercase text-xs" style="letter-spacing:.04em;">
                    <tr>
                        <th class="ps-4">Item</th>
                        <th>SKU / Barcode</th>
                        <th>Archived</th>
                        <th class="pe-4 text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $i)
                        <tr>
                            <td class="ps-4">
                                <span class="fw-medium">{{ $i->brand ?? '—' }}</span>
                                <span class="text-muted-foreground">{{ $i->model_name }}</span>
                            </td>
                            <td class="font-monospace text-muted-foreground text-xs">
                                <div>{{ $i->sku }}</div>
                                <div class="text-2xs" style="opacity:.7;">{{ $i->barcode }}</div>
                            </td>
                            <td class="text-muted-foreground text-sm">
                                {{ $i->deleted_at->format('d M Y') }}
                                <span class="text-faint">· purges {{ $i->deleted_at->copy()->addDays(30)->format('d M Y') }}</span>
                            </td>
                            <td class="pe-4">
                                <div class="d-flex justify-content-end gap-2">
                                    <form method="POST" action="{{ route('tenant.inventory.restore', $i) }}" class="m-0">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn btn-sm btn-light">
                                            <i class="bi bi-arrow-counterclockwise me-1"></i> Restore
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('tenant.inventory.force-delete', $i) }}" class="m-0">
                                        @csrf
                                        @method('DELETE')
                                        <button type="button" class="btn btn-sm btn-light text-danger"
                                                data-confirm="Permanently delete {{ $i->brand }} {{ $i->model_name }}? This cannot be undone."
                                                data-confirm-title="Delete permanently"
                                                data-confirm-label="Delete forever">
                                            <i class="bi bi-trash me-1"></i> Delete now
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if ($items->hasPages())
        <div class="mt-3">{{ $items->links() }}</div>
    @endif
@else
    <div class="glass card-lift rounded-4 text-center p-5 animate-fade-up">
        <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary-subtle text-primary mb-3"
              style="width:3rem;height:3rem;"><i class="bi bi-archive fs-4"></i></span>
        <h2 class="h5 fw-semibold font-display">
            {{ ($search ?? '') !== '' ? 'No matches' : 'Archive is empty' }}
        </h2>
        <p class="text-muted-foreground mb-0">
            {{ ($search ?? '') !== ''
                ? 'No archived item matches that search.'
                : 'Archived items will appear here for 30 days before being purged.' }}
        </p>
    </div>
@endif
