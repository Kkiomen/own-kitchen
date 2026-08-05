<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        {{-- viewport-fit=cover lets the layout reach under the notch; the safe
             area is paid back in CSS where it actually matters. --}}
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="#55b850">
        <meta name="description" content="Domowa książka kucharska: przepisy, składniki i gotowanie krok po kroku.">

        {{-- Private household app. The header set by App\Http\Middleware\PreventIndexing
             says the same thing to every response; this repeats it where a crawler
             looks first, and survives a host that strips headers. --}}
        <meta name="robots" content="noindex, nofollow, noarchive, noimageindex">

        <link rel="manifest" href="/manifest.webmanifest">

        {{-- iOS ignores the manifest for these, so they are repeated by hand. --}}
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="Kuchnia">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
