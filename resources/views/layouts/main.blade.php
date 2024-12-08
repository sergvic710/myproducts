<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laravel</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet"/>

    <!-- Styles / Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
@include('partipals.navbar')
@include ('partipals.aside')

<div class="p-4 sm:ml-64">
    <div class="mt-14">
        <h1 class="font-bold text-2xl">
            {{ $title ?? '' }}
        </h1>
    </div>
    <div class="p-4 border-2 border-gray-200  rounded-lg dark:border-gray-700 mt-2">
        @yield('content')
    </div>
</div>
</body>
</html>
