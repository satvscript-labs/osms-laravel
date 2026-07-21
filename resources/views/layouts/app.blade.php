<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'OSMS') — OSMS</title>
    {{-- SEC-04: Plus Jakarta Sans is self-hosted via @fontsource in the Vite bundle. --}}
    @vite(['resources/sass/app.scss', 'resources/js/app.js'])
    @stack('head')
</head>
<body>
    <div class="app-shell">
        @include('partials.sidebar')

        <div class="app-main">
            {{-- Mobile top bar --}}
            <div class="d-md-none d-flex align-items-center justify-content-between border-bottom bg-white px-3 py-2 no-print">
                <button class="btn btn-sm btn-light" type="button" data-bs-toggle="offcanvas"
                        data-bs-target="#mobileSidebar" aria-label="Open navigation menu">
                    <i class="bi bi-list fs-5" aria-hidden="true"></i>
                </button>
                <span class="fw-semibold font-display">OSMS</span>
                <span style="width:2rem;"></span>
            </div>

            @if (session('status'))
                <div class="px-4 px-md-5 pt-4 no-print">
                    {{-- UX-06 — announce flash messages to assistive tech. --}}
                    <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-0 rounded-3" role="status" aria-live="polite">
                        <i class="bi bi-check-circle-fill"></i>
                        <div>{{ session('status') }}</div>
                        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                </div>
            @endif

            @if (session('error'))
                <div class="px-4 px-md-5 pt-4 no-print">
                    <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-0 rounded-3" role="alert">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <div>{{ session('error') }}</div>
                        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                </div>
            @endif

            @include('partials.subscription-banner')

            <div class="page-enter">
                @yield('content')
            </div>
        </div>
    </div>

    {{-- Mobile offcanvas sidebar --}}
    <div class="offcanvas offcanvas-start" tabindex="-1" id="mobileSidebar" style="width:240px;">
        @include('partials.sidebar', ['mobile' => true])
    </div>

    {{-- Global search palette (tenant users only) --}}
    @auth
        @unless (auth()->user()->isSuperadmin())
            @include('partials.global-search')
        @endunless
    @endauth

    {{-- Reusable confirm-action modal (premium replacement for window.confirm) --}}
    @include('partials.confirm-modal')

    @stack('scripts')
</body>
</html>
