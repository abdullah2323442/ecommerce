<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        @php($brandMeta = app(\App\Services\Site\Site::class)->forViewer())
        <title inertia>{{ $brandMeta['brand']['name'] ?: config('app.name', 'Laravel') }}</title>
        @if ($brandMeta['brand']['favicon'])
            <link rel="icon" href="{{ $brandMeta['brand']['favicon'] }}">
        @endif
        <link rel="manifest" href="{{ route('pwa.manifest', [], false) }}">
        <meta name="theme-color" content="{{ $brandMeta['brand']['theme_color'] }}">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="{{ mb_substr($brandMeta['brand']['name'], 0, 12) }}">
        <link rel="apple-touch-icon" href="{{ route('pwa.icon', ['name' => 'apple-180'], false) }}">
        @if ($brandMeta['site']['seo']['description'])
            <meta name="description" content="{{ $brandMeta['site']['seo']['description'] }}">
        @endif

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=hind-siliguri:400,500,600,700|baloo-da-2:600,700,800|cormorant-garamond:500,600&display=swap" rel="stylesheet" />

        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
