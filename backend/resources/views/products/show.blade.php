cat << 'EOF' > resources/views/products/show.blade.php
@extends('layouts.app')
@section('title', $product->name . ' - Organic Farm')

@section('content')
@php
    $stock = method_exists($product, 'batches')
        ? (int) $product->batches()
            ->where('remaining_quantity', '>', 0)
            ->where(function ($q) {
                $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', now()->toDateString());
            })
            ->sum('remaining_quantity')
        : (int) ($product->stock_quantity ?? $product->quantity ?? $product->stock ?? 0);
    $price = (float) ($product->sale_price ?? $product->price ?? 0);
@endphp

<div class="max-w-5xl mx-auto px-4 py-8">
    <a href="{{ route('products.index') }}" class="inline-flex items-center text-sm text-stone-500 hover:text-emerald-700 mb-6">
        ← Quay lại danh sách sản phẩm
    </a>

    <div class="bg-white rounded-2xl border border-stone-200 p-6 sm:p-8 grid grid-cols-1 md:grid-cols-2 gap-8 shadow-sm">
        <div class="aspect-square rounded-xl overflow-hidden bg-stone-100 border border-stone-100">
            <img src="{{ !empty($product->image) ? asset('storage/' . $product->image) : 'https://placehold.co/500x500/ecfdf5/047857?text=' . urlencode($product->name) }}"
                 alt="{{ $product->name }}"
                 class="w-full h-full object-cover">
        </div>

        <div class="flex flex-col justify-between">
            <div>
                <span class="inline-block text-xs font-semibold px-2.5 py-1 rounded-full {{ $stock > 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-600' }}">
                    {{ $stock > 0 ? "Còn hàng ({$stock} sản phẩm)" : 'Tạm hết hàng' }}
                </span>
                <h1 class="text-2xl sm:text-3xl font-bold text-stone-800 mt-3">{{ $product->name }}</h1>
                <p class="text-2xl font-bold text-emerald-700 mt-4">{{ number_format($price, 0, ',', '.') }}đ</p>
                <p class="text-stone-600 text-sm leading-relaxed mt-4">
                    {{ $product->description ?? 'Nông sản hữu cơ đạt chuẩn chất lượng, thu hoạch tươi mới mỗi ngày từ trang trại Organic Farm.' }}
                </p>
            </div>

            <div class="pt-6 mt-6 border-t border-stone-100">
                <div class="flex flex-wrap items-center gap-4">
                    {{-- Bộ chọn số lượng --}}
                    <div class="inline-flex items-center rounded-xl border border-stone-300 bg-stone-50">
                        <button type="button"
                                onclick="const inp = document.getElementById('detail-quantity-input'); if(parseInt(inp.value) > 1) inp.value = parseInt(inp.value) - 1;"
                                class="px-3.5 py-2.5 text-stone-600 hover:text-emerald-700 font-bold">
                            −
                        </button>
                        <input type="number"
                               id="detail-quantity-input"
                               value="1"
                               min="1"
                               max="{{ max($stock, 1) }}"
                               class="w-14 text-center bg-transparent text-sm font-semibold text-stone-800 focus:outline-none">
                        <button type="button"
                                onclick="const inp = document.getElementById('detail-quantity-input'); inp.value = parseInt(inp.value || 1) + 1;"
                                class="px-3.5 py-2.5 text-stone-600 hover:text-emerald-700 font-bold">
                            +
                        </button>
                    </div>

                    {{-- Nút Thêm vào giỏ --}}
                    <button type="button"
                            data-add-to-cart
                            data-product-id="{{ $product->id }}"
                            data-qty-source="#detail-quantity-input"
                            {{ $stock <= 0 ? 'disabled' : '' }}
                            class="flex-1 inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-700 disabled:bg-stone-300 disabled:cursor-not-allowed text-white font-semibold text-sm transition shadow-sm">
                        <svg data-spinner class="hidden animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        <span data-btn-text>{{ $stock > 0 ? 'Thêm vào giỏ hàng' : 'Hết hàng' }}</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
EOF