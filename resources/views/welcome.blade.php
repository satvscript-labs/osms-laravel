<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OSMS — The premium optical store SaaS</title>
    {{-- SEC-04: Plus Jakarta Sans is self-hosted via @fontsource in the Vite bundle. --}}
    @vite(['resources/sass/app.scss', 'resources/js/app.js'])
</head>
<body class="bg-spotlight">
    {{-- Header --}}
    <header class="container py-4 d-flex align-items-center justify-content-between" style="max-width:72rem;">
        <a href="{{ route('home') }}" class="d-inline-flex align-items-center gap-2 text-decoration-none text-dark">
            <span class="d-inline-flex align-items-center justify-content-center bg-primary text-white rounded-3 shadow-sm"
                  style="width:2rem;height:2rem;"><i class="bi bi-eye"></i></span>
            <span class="fs-5 fw-semibold font-display">OSMS</span>
        </a>
        <nav class="d-none d-md-flex gap-4 small">
            <a href="#features" class="text-decoration-none text-muted-foreground">Features</a>
            <a href="#security" class="text-decoration-none text-muted-foreground">Security</a>
        </nav>
        <div class="d-flex align-items-center gap-2">
            @auth
                <a href="{{ route('dashboard') }}" class="btn btn-primary btn-sm">Go to dashboard</a>
            @else
                <a href="{{ route('login') }}" class="d-none d-sm-inline small fw-medium text-decoration-none text-muted-foreground">Sign in</a>
                <a href="{{ route('register') }}" class="btn btn-primary btn-sm">
                    Get started <i class="bi bi-arrow-right ms-1"></i>
                </a>
            @endauth
        </div>
    </header>

    {{-- Hero --}}
    <section class="container text-center py-5" style="max-width:72rem;">
        <span class="animate-fade-up d-inline-flex align-items-center gap-2 rounded-pill border bg-white bg-opacity-75 px-3 py-1 mb-4 text-muted-foreground"
              style="font-size:var(--text-xs);">
            <i class="bi bi-stars text-primary"></i> The premium SaaS built for modern optical retail
        </span>
        <h1 class="animate-fade-up display-4 fw-semibold font-display mb-3">
            The optical store,<br>
            <span class="text-primary">beautifully managed.</span>
        </h1>
        <p class="animate-fade-up lead text-muted-foreground mx-auto mb-4" style="max-width:36rem; font-size:1.05rem;">
            Patient records, prescriptions, inventory, kanban orders, and live analytics —
            all in one elegant workspace. Built for independent opticians.
        </p>
        <div class="animate-fade-up d-flex flex-wrap justify-content-center gap-2 mb-5">
            <a href="{{ route('register') }}" class="btn btn-primary btn-lg px-4">
                Start free <i class="bi bi-arrow-right ms-1"></i>
            </a>
            <a href="{{ route('login') }}" class="btn btn-outline-secondary btn-lg px-4">Sign in</a>
        </div>

        {{-- Mock dashboard preview --}}
        <div class="animate-fade-up mx-auto" style="max-width:56rem;">
            <div class="glass card-lift rounded-4 p-2">
                <div class="bg-white rounded-3 border overflow-hidden text-start">
                    <div class="d-flex align-items-center gap-2 border-bottom px-3 py-2">
                        <span class="rounded-circle bg-danger opacity-50" style="width:.6rem;height:.6rem;"></span>
                        <span class="rounded-circle bg-warning opacity-50" style="width:.6rem;height:.6rem;"></span>
                        <span class="rounded-circle bg-success opacity-50" style="width:.6rem;height:.6rem;"></span>
                        <span class="ms-2 text-muted-foreground" style="font-size:var(--text-xs);">osms.satvscript.com/tenant</span>
                    </div>
                    <div class="row g-3 p-4">
                        @foreach ([['Today\'s sales','₹ 42,890'],['Pending orders','12'],['Low stock','3']] as $m)
                            <div class="col-md-4">
                                <div class="border rounded-3 bg-body p-3">
                                    <p class="text-muted-foreground mb-1" style="font-size:var(--text-xs);">{{ $m[0] }}</p>
                                    <p class="h4 fw-semibold font-display mb-0">{{ $m[1] }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Features --}}
    <section id="features" class="container py-5" style="max-width:72rem;">
        <div class="text-center mb-5">
            <h2 class="display-6 fw-semibold font-display mb-2">Everything an optical store needs</h2>
            <p class="text-muted-foreground mx-auto" style="max-width:36rem;">
                From the first eye exam to the final receipt — a single workspace that respects your
                time and your customers' trust.
            </p>
        </div>
        <div class="row g-3">
            @php
                $features = [
                    ['bi-people', 'Patient & Rx records', 'Capture full optometry data — OD/OS, SPH, CYL, axis, ADD, PD — alongside lifetime patient history.'],
                    ['bi-box-seam', 'Smart inventory', 'Auto-generated SKUs and thermal-printer-ready barcodes for every frame and lens batch.'],
                    ['bi-upc-scan', 'Barcode POS', 'Scan to add. USB or Bluetooth scanners are fully supported across every screen.'],
                    ['bi-kanban', 'Kanban orders', 'Track every estimate from pending → ready → delivered with payments and advances built in.'],
                    ['bi-bar-chart', 'Financial analytics', 'Revenue, COGS, gross profit, pending dues, best-selling brands. All in real time.'],
                    ['bi-shield-lock', 'Tenant isolation', 'Every store operates in a strictly isolated data environment — Store A can never see Store B.'],
                ];
            @endphp
            @foreach ($features as $f)
                <div class="col-md-6 col-lg-4">
                    <div class="card card-lift border-0 shadow-sm rounded-4 h-100">
                        <div class="card-body p-4">
                            <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary-subtle text-primary mb-3"
                                  style="width:2.5rem;height:2.5rem;"><i class="bi {{ $f[0] }} fs-5"></i></span>
                            <h3 class="h6 fw-semibold font-display">{{ $f[1] }}</h3>
                            <p class="text-muted-foreground small mb-0">{{ $f[2] }}</p>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- CTA --}}
    <section id="security" class="container pb-5" style="max-width:72rem;">
        <div class="glass rounded-4 text-center p-5">
            <h2 class="display-6 fw-semibold font-display mb-2">Ready to modernize your store?</h2>
            <p class="text-muted-foreground mx-auto mb-4" style="max-width:30rem;">
                Get started in minutes. No credit card, no setup fees, no nonsense.
            </p>
            <a href="{{ route('register') }}" class="btn btn-primary btn-lg px-4">
                Create your store <i class="bi bi-arrow-right ms-1"></i>
            </a>
        </div>
    </section>

    {{-- Footer --}}
    <footer class="border-top bg-white bg-opacity-50">
        <div class="container py-4 d-flex flex-column flex-md-row align-items-center justify-content-between gap-2 text-muted-foreground"
             style="max-width:72rem; font-size:var(--text-xs);">
            <div class="d-flex align-items-center gap-2">
                <span class="d-inline-flex align-items-center justify-content-center bg-primary text-white rounded"
                      style="width:1.25rem;height:1.25rem;"><i class="bi bi-eye" style="font-size:var(--text-2xs);"></i></span>
                <span class="fw-medium font-display">OSMS</span>
                <span>· The premium optical SaaS.</span>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-3">
                <a href="{{ route('legal.terms') }}" class="text-muted-foreground text-decoration-none">Terms</a>
                <a href="{{ route('legal.privacy') }}" class="text-muted-foreground text-decoration-none">Privacy</a>
                <a href="{{ route('legal.refund') }}" class="text-muted-foreground text-decoration-none">Refunds</a>
                <a href="{{ route('legal.contact') }}" class="text-muted-foreground text-decoration-none">Contact</a>
            </div>
            <span>&copy; {{ date('Y') }} OSMS. All rights reserved.</span>
        </div>
    </footer>
</body>
</html>
