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
            {{-- P5 — read-only "view as store". Above everything, on every page. --}}
            @include('partials.impersonation-band')

            {{-- Mobile top bar --}}
            <div class="d-md-none d-flex align-items-center justify-content-between border-bottom bg-white px-3 py-2 no-print">
                <button class="btn btn-sm btn-light" type="button" data-bs-toggle="offcanvas"
                        data-bs-target="#mobileSidebar" aria-label="Open navigation menu">
                    <i class="bi bi-list fs-5" aria-hidden="true"></i>
                </button>
                <span class="fw-semibold font-display">OSMS</span>
                <span style="width:2rem;"></span>
            </div>

            {{-- Flash messages float above the page instead of being inserted into
                 it. Inline they pushed every page down on arrival and snapped it
                 back on dismiss, which moved whatever the user was about to click.
                 UX-06 — still announced to assistive tech. --}}
            @if (session('status') || session('error'))
                <div class="toast-rail no-print">
                    @if (session('status'))
                        <div class="osms-toast osms-toast-success" role="status" aria-live="polite" data-toast>
                            <i class="bi bi-check-circle-fill osms-toast-icon"></i>
                            <div class="osms-toast-body">{{ session('status') }}</div>
                            <button type="button" class="osms-toast-close" aria-label="Dismiss" data-toast-close>
                                <i class="bi bi-x-lg"></i>
                            </button>
                            <span class="osms-toast-timer" aria-hidden="true"></span>
                        </div>
                    @endif

                    @if (session('error'))
                        {{-- Errors do not auto-dismiss: something went wrong and the
                             user must be the one to acknowledge it. --}}
                        <div class="osms-toast osms-toast-error" role="alert" data-toast data-toast-sticky>
                            <i class="bi bi-exclamation-triangle-fill osms-toast-icon"></i>
                            <div class="osms-toast-body">{{ session('error') }}</div>
                            <button type="button" class="osms-toast-close" aria-label="Dismiss" data-toast-close>
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                    @endif
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
