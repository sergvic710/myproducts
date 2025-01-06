<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laravel</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet"/>

{{--    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />--}}
{{--    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>--}}

    <!-- Styles / Scripts -->
{{--    <script src="https://cdn.jsdelivr.net/npm/flowbite@2.5.2/dist/flowbite.min.js"></script>--}}
    @vite(['resources/css/app.css'])
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
@vite(['resources/js/app.js'])
@stack('scripts')
</body>
</html>
