@if ($paginator->hasPages())
    @php
        // The default Laravel window renders `onEachSide` (3) links on both sides
        // plus both ends — on a 75-page list that is ~11 links and it dominates
        // the page. We build a tighter window here instead of relying on
        // $elements, so every paginated list in the app gets the same compact
        // control without each call site having to pass ->onEachSide().
        $current = $paginator->currentPage();
        $last = $paginator->lastPage();
        $side = 1;

        $window = range(max(1, $current - $side), min($last, $current + $side));
        $pages = collect([1])->merge($window)->push($last)
            ->filter(fn ($p) => $p >= 1 && $p <= $last)
            ->unique()->sort()->values();
    @endphp

    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}"
         class="d-flex flex-wrap align-items-center justify-content-between gap-3">

        <p class="text-muted-foreground text-sm mb-0">
            Showing <span class="fw-semibold">{{ $paginator->firstItem() }}</span>–<span
                class="fw-semibold">{{ $paginator->lastItem() }}</span>
            of <span class="fw-semibold">{{ number_format($paginator->total()) }}</span>
        </p>

        <ul class="pagination mb-0">
            {{-- Previous --}}
            <li class="page-item {{ $paginator->onFirstPage() ? 'disabled' : '' }}">
                @if ($paginator->onFirstPage())
                    <span class="page-link" aria-hidden="true"><i class="bi bi-chevron-left"></i></span>
                @else
                    <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev"
                       aria-label="{{ __('pagination.previous') }}"><i class="bi bi-chevron-left"></i></a>
                @endif
            </li>

            @php $previous = null; @endphp
            @foreach ($pages as $page)
                {{-- A gap marker stands in for every page we skipped. --}}
                @if ($previous !== null && $page - $previous > 1)
                    <li class="page-item disabled" aria-hidden="true">
                        <span class="page-link osms-page-gap">…</span>
                    </li>
                @endif

                <li class="page-item {{ $page === $current ? 'active' : '' }}"
                    @if ($page === $current) aria-current="page" @endif>
                    @if ($page === $current)
                        <span class="page-link">{{ $page }}</span>
                    @else
                        <a class="page-link" href="{{ $paginator->url($page) }}">{{ $page }}</a>
                    @endif
                </li>

                @php $previous = $page; @endphp
            @endforeach

            {{-- Next --}}
            <li class="page-item {{ $paginator->hasMorePages() ? '' : 'disabled' }}">
                @if ($paginator->hasMorePages())
                    <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next"
                       aria-label="{{ __('pagination.next') }}"><i class="bi bi-chevron-right"></i></a>
                @else
                    <span class="page-link" aria-hidden="true"><i class="bi bi-chevron-right"></i></span>
                @endif
            </li>
        </ul>
    </nav>
@endif
