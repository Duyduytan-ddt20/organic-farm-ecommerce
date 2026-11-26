<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Organic Farm')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-stone-50 text-stone-800 antialiased">

    {{-- Header --}}
    @include('partials.header')

    {{-- Main Content --}}
    <main>
        @yield('content')
    </main>

    {{-- Toast Notification Container (Góc trên bên phải, không che nội dung chính) --}}
    <div id="toast-container"
         aria-live="polite"
         class="fixed top-20 right-4 z-50 flex flex-col gap-3 w-full max-w-sm pointer-events-none px-4 sm:px-0">
    </div>

    <script src="{{ asset('js/cart.js') }}" defer></script>
    @stack('scripts')
</body>
</html>