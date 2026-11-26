@extends('layouts.app')
@section('title', 'Giỏ hàng của bạn - Organic Farm')

@section('content')
@php
    $totalAmount = 0;
    foreach ($cart as $item) {
        $totalAmount += $item['price'] * $item['quantity'];
    }
@endphp

<div class="max-w-5xl mx-auto px-4 py-8">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-stone-800">Giỏ hàng của bạn</h1>
        <a href="{{ route('products.index') }}" class="text-sm font-semibold text-emerald-700 hover:text-emerald-800">
            ← Tiếp tục mua sắm
        </a>
    </div>

    {{-- Trạng thái Giỏ hàng trống --}}
    <div id="cart-empty-state" class="{{ empty($cart) ? '' : 'hidden' }} bg-white rounded-2xl border border-stone-200 p-12 text-center shadow-sm">
        <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center">
            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
            </svg>
        </div>
        <h2 class="text-lg font-semibold text-stone-800">Giỏ hàng của bạn đang trống</h2>
        <p class="text-sm text-stone-500 mt-1">Hãy chọn thêm các nông sản hữu cơ tươi sạch từ trang trại nhé!</p>
        <a href="{{ route('products.index') }}" class="mt-6 inline-flex items-center px-6 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold transition">
            Khám phá sản phẩm
        </a>
    </div>

    {{-- Danh sách sản phẩm trong giỏ --}}
    <div id="cart-content-wrapper" class="{{ empty($cart) ? 'hidden' : 'grid' }} grid-cols-1 lg:grid-cols-3 gap-8">
        <div class="lg:col-span-2 space-y-4" id="cart-items-list">
            @foreach($cart as $productId => $item)
                @php
                    $subtotal = $item['price'] * $item['quantity'];
                @endphp
                <div data-cart-row="{{ $productId }}" class="bg-white rounded-2xl border border-stone-200 p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4 shadow-sm transition">
                    <div class="flex items-center gap-4">
                        <img src="{{ !empty($item['image']) ? asset('storage/' . $item['image']) : 'https://placehold.co/120x120/ecfdf5/047857?text=' . urlencode($item['name']) }}"
                             alt="{{ $item['name'] }}"
                             class="w-20 h-20 rounded-xl object-cover bg-stone-100 border border-stone-100 shrink-0">
                        <div>
                            <h3 class="font-semibold text-stone-800">{{ $item['name'] }}</h3>
                            <p class="text-sm text-emerald-700 font-medium mt-0.5">
                                {{ number_format($item['price'], 0, ',', '.') }}đ<span class="text-xs text-stone-400">/{{ $item['unit'] ?? 'sp' }}</span>
                            </p>
                            <p class="text-xs text-stone-400 mt-1">Tồn kho khả dụng: <span data-item-stock="{{ $productId }}" class="font-medium text-stone-600">{{ $item['stock'] ?? 0 }}</span></p>
                        </div>
                    </div>

                    <div class="flex items-center justify-between sm:justify-end gap-3 border-t sm:border-t-0 pt-3 sm:pt-0 border-stone-100">
                        {{-- Loading Spinner kiểm tra tồn kho lô --}}
                        <span data-row-spinner="{{ $productId }}" class="hidden items-center gap-1.5 text-xs text-emerald-700 font-medium">
                            <svg class="animate-spin h-4 w-4 text-emerald-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <span>Đang kiểm tra lô...</span>
                        </span>

                        {{-- Cụm nút tăng giảm số lượng --}}
                        <div data-qty-controls="{{ $productId }}" class="inline-flex items-center rounded-xl border border-stone-300 bg-stone-50 transition">
                            <button type="button"
                                    data-qty-decrease
                                    data-product-id="{{ $productId }}"
                                    class="px-3 py-1.5 text-stone-600 hover:text-emerald-700 disabled:opacity-40 disabled:cursor-not-allowed font-bold">
                                −
                            </button>
                            <input type="number"
                                   data-qty-input
                                   data-product-id="{{ $productId }}"
                                   value="{{ $item['quantity'] }}"
                                   min="1"
                                   class="w-12 text-center bg-transparent text-sm font-semibold text-stone-800 focus:outline-none disabled:opacity-50">
                            <button type="button"
                                    data-qty-increase
                                    data-product-id="{{ $productId }}"
                                    class="px-3 py-1.5 text-stone-600 hover:text-emerald-700 disabled:opacity-40 disabled:cursor-not-allowed font-bold">
                                +
                            </button>
                        </div>

                        {{-- Thành tiền của dòng --}}
                        <div class="text-right min-w-[95px]">
                            <span data-item-subtotal="{{ $productId }}" class="font-bold text-stone-800">
                                {{ number_format($subtotal, 0, ',', '.') }}đ
                            </span>
                        </div>

                        {{-- Nút xóa --}}
                        <button type="button"
                                data-remove-item
                                data-product-id="{{ $productId }}"
                                title="Xóa sản phẩm"
                                class="p-2 text-stone-400 hover:text-red-600 disabled:opacity-40 rounded-lg hover:bg-red-50 transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                        </button>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Tóm tắt đơn hàng --}}
        <div class="bg-white rounded-2xl border border-stone-200 p-6 h-fit shadow-sm">
            <h2 class="text-lg font-bold text-stone-800 pb-4 border-b border-stone-100">Tóm tắt đơn hàng</h2>
            <div class="py-4 space-y-3 text-sm">
                <div class="flex justify-between text-stone-600">
                    <span>Tạm tính</span>
                    <span id="cart-subtotal-amount" class="font-medium text-stone-800">{{ number_format($totalAmount, 0, ',', '.') }}đ</span>
                </div>
                <div class="flex justify-between text-stone-600">
                    <span>Phí giao hàng</span>
                    <span class="text-emerald-700 font-medium">Tính khi thanh toán</span>
                </div>
            </div>
            <div class="pt-4 border-t border-stone-100 flex justify-between items-center">
                <span class="font-semibold text-stone-800">Tổng cộng</span>
                <span id="cart-total-amount" class="text-xl font-bold text-emerald-700">{{ number_format($totalAmount, 0, ',', '.') }}đ</span>
            </div>
            <button type="button"
                    onclick="alert('Chuyển sang bước đặt hàng (POST /api/v1/orders)!')"
                    class="mt-6 w-full py-3 px-4 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-sm transition shadow-sm">
                Tiến hành đặt hàng
            </button>
        </div>
    </div>
</div>
@endsection
