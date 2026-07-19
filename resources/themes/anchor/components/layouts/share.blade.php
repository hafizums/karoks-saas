<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="googlebot" content="noindex, nofollow, noarchive">

    @if(isset($seo->title))
        <title>{{ $seo->title }}</title>
    @else
        <title>{{ $title ?? 'Shared Karaoke' }}</title>
    @endif

    <x-favicon></x-favicon>

    @vite(['resources/themes/anchor/assets/css/app.css', 'resources/themes/anchor/assets/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-50 text-zinc-900 dark:bg-zinc-900 dark:text-zinc-100">
    <main class="mx-auto w-full max-w-3xl px-4 py-6 sm:px-6">
        {{ $slot }}
    </main>
</body>
</html>
