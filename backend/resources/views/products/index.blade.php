@extends('layouts.app')
@section('title', 'Nông Sản Hữu Cơ - Organic Farm')

@section('content')
<div class="max-w-6xl mx-auto px-4 py-8">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
        <div>
            <h1 class="text-2xl font-bold text-stone-800">Nông sản hữu cơ tươi sạch</h1>
            <p class="text-sm text-stone-500 mt-1">Thu hoạch trong ngày, truy xuất nguồn gốc rõ ràng</p>
        </div>
        <a href="{{ route('cart.index') }}" class="inline-flex items-center gap-2 text-sm font-semibold text-emerald-700 hover:text-emerald-800">
            Xem giỏ hàng →
        </a>
    </div>

    @if($products->isEmpty())
        <div class="bg-white rounded-2xl border border-stone-200 p-10 text-center text-stone-500">
            Chưa có sản phẩm nào trong cơ sở dữ liệu.
        </div>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
            @foreach($products as $product)
                @php
                    $stock = method_exists($product, 'batches')
                        ? (int) $product->batches()
                            ->where('available_quantity', '>', 0)
                            ->where(function ($q) {
                                $q->whereNull('expires_at')->orWhere('expires_at', '>=', now());
                            })
                            ->sum('available_quantity')
                        : 0;
                    $price = (float) ($product->base_price ?? 0);
                @endphp

                <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden shadow-sm hover:shadow-md transition flex flex-col justify-between">
                    <div>
                        <a href="{{ route('products.show', $product->id) }}" class="block aspect-square bg-stone-100 overflow-hidden">
                            <img src="{{ !empty($product->image_path) ? asset('storage/' . $product->image_path) : 'https://placehold.co/400x400/ecfdf5/047857?text=' . urlencode($product->name) }}"
                                 alt="{{ $product->name }}"
                                 class="w-full h-full object-cover hover:scale-105 transition duration-300">
                        </a>
                        <div class="p-4">
                            <a href="{{ route('products.show', $product->id) }}" class="font-semibold text-stone-800 hover:text-emerald-700 line-clamp-2">
                                {{ $product->name }}
                            </a>
                            <div class="mt-2 flex items-center justify-between">
                                <span class="text-lg font-bold text-emerald-700">
                                    {{ number_format($price, 0, ',', '.') }}đ<span class="text-xs font-normal text-stone-500">/{{ $product->unit ?? 'sp' }}</span>
                                </span>
                                <span class="text-xs px-2 py-0.5 rounded-full {{ $stock > 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-600' }}">
                                    {{ $stock > 0 ? "Còn {$stock}" : 'Hết hàng' }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="p-4 pt-0">
                        @if($stock > 0)
                            <button type="button"
                                    data-add-to-cart
                                    data-product-id="{{ $product->id }}"
                                    data-quantity="1"
                                    class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 disabled:bg-emerald-400 disabled:cursor-not-allowed text-white font-medium text-sm transition shadow-sm">
                                <svg data-spinner class="hidden animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                                <span data-btn-text>Thêm vào giỏ</span>
                            </button>
                        @else
                            <button type="button"
                                    disabled
                                    class="w-full inline-flex items-center justify-center px-4 py-2.5 rounded-xl bg-stone-200 text-stone-500 font-medium text-sm cursor-not-allowed">
                                Hết hàng
                            </button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
