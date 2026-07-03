<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('code') · OSMS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/sass/app.scss'])
</head>
<body class="bg-spotlight">
    <main class="min-vh-100 d-flex align-items-center justify-content-center p-4">
        <div class="glass rounded-4 shadow-lg text-center animate-fade-up" style="max-width:30rem; padding:2.75rem 2.25rem;">
            <div class="section-label mb-2">@yield('code')</div>
            <h1 class="font-display h3 mb-2">@yield('title')</h1>
            <p class="text-muted-foreground mb-4">@yield('message')</p>
            <a href="{{ url('/') }}" class="btn btn-primary">
                <i class="bi bi-arrow-left me-1"></i> Back to safety
            </a>
        </div>
    </main>
</body>
</html>
