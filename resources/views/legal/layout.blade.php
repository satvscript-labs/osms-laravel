<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('heading') — OSMS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/sass/app.scss'])
</head>
<body class="bg-spotlight">
    <header class="container py-4 d-flex align-items-center justify-content-between" style="max-width:52rem;">
        <a href="{{ route('home') }}" class="d-inline-flex align-items-center gap-2 text-decoration-none">
            <span class="d-inline-flex align-items-center justify-content-center bg-primary text-white rounded"
                  style="width:1.5rem;height:1.5rem;"><i class="bi bi-eye" style="font-size:.85rem;"></i></span>
            <span class="fw-semibold font-display text-dark">OSMS</span>
        </a>
        <a href="{{ route('home') }}" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i> Back to home</a>
    </header>

    <main class="container pb-5" style="max-width:52rem;">
        <div class="glass rounded-4 p-4 p-md-5 animate-fade-up">
            @unless (app()->isProduction())
                <div class="alert alert-warning d-flex align-items-center gap-2 rounded-3" role="alert">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <div><strong>Draft template.</strong> Replace the bracketed placeholders (via the
                    <code>SAAS_*</code> env vars) and have this reviewed by legal counsel before launch.</div>
                </div>
            @endunless

            <p class="section-label mb-1">Legal</p>
            <h1 class="font-display h2 mb-1">@yield('heading')</h1>
            <p class="text-muted-foreground text-sm mb-4">Last updated: {{ config('saas.legal_updated', 'On launch') }}</p>

            <div class="legal-prose">
                @yield('content')
            </div>
        </div>

        <div class="text-center text-muted-foreground text-sm mt-4 d-flex flex-wrap justify-content-center gap-3">
            <a href="{{ route('legal.terms') }}" class="text-decoration-none">Terms</a>
            <a href="{{ route('legal.privacy') }}" class="text-decoration-none">Privacy</a>
            <a href="{{ route('legal.refund') }}" class="text-decoration-none">Refund &amp; Cancellation</a>
            <a href="{{ route('legal.contact') }}" class="text-decoration-none">Contact</a>
        </div>
    </main>
</body>
</html>
