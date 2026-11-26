<?php

use App\Http\Controllers\CartController;
use App\Models\Product;
use Illuminate\Support\Facades\Route;

// Trang chủ & Danh sách sản phẩm (lấy dữ liệu thật từ bảng products)
Route::get('/', function () {
    return redirect()->route('products.index');
});

Route::get('/products', function () {
    $products = Product::latest()->get();
    return view('products.index', compact('products'));
})->name('products.index');

Route::get('/products/{id}', function ($id) {
    $product = Product::findOrFail($id);
    return view('products.show', compact('product'));
})->name('products.show');

// Giỏ hàng bất đồng bộ AJAX
Route::prefix('cart')->name('cart.')->group(function () {
    Route::get('/', [CartController::class, 'index'])->name('index');
    Route::post('/add', [CartController::class, 'add'])->name('add');
    Route::patch('/update/{productId}', [CartController::class, 'update'])->name('update');
    Route::delete('/remove/{productId}', [CartController::class, 'remove'])->name('remove');
});