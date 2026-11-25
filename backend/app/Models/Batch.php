<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Batch extends Model
{
    protected $fillable = [
        'product_id', 'ma_lo', 'ngay_thu_hoach', 'ngay_het_han',
        'so_luong_nhap', 'so_luong_ton', 'so_luong_hao_hut',
        'phan_tram_giam', 'qr_code', 'trang_thai',
    ];

    protected $casts = [
        'ngay_thu_hoach'   => 'date:Y-m-d',
        'ngay_het_han'     => 'date:Y-m-d',
        'so_luong_nhap'    => 'decimal:2',
        'so_luong_ton'     => 'decimal:2',
        'so_luong_hao_hut' => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}