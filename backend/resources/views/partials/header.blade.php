<a href="{{ route('cart.index') }}" class="relative inline-flex items-center p-2 text-emerald-800 hover:text-emerald-600 transition">
    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
    </svg>
    @php
        $cartCount = collect(session('cart', []))->sum('quantity');
    @endphp
    <span id="header-cart-badge"
          class="absolute -top-1 -right-1 inline-flex items-center justify-center px-1.5 py-0.5 text-xs font-bold leading-none text-white bg-emerald-600 rounded-full transition-transform duration-200 {{ $cartCount > 0 ? '' : 'hidden' }}">
        {{ $cartCount }}
    </span>
</a>