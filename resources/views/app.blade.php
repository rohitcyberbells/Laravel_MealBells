<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- The skip-import endpoints answer with JSON rather than an Inertia
         response, so that modal posts with fetch and needs the token here. --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title inertia>{{ config('app.name', 'MEALBELLS') }}</title>
    {{-- Runs before the stylesheet so the first paint is already in the right
         theme; set from Vue after mount, a light-mode user would see a dark
         flash on every load. Storage can throw in a private window, hence the
         try. --}}
    <script>
        (function () {
            var theme = 'dark';
            try {
                var stored = localStorage.getItem('mealbells-theme');
                if (stored === 'light' || stored === 'dark') {
                    theme = stored;
                } else if (window.matchMedia('(prefers-color-scheme: light)').matches) {
                    theme = 'light';
                }
            } catch (e) {}
            document.documentElement.dataset.theme = theme;
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="font-sans antialiased bg-slate-950 text-white">
    @inertia
</body>
</html>
