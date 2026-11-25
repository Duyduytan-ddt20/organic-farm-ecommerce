<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'category_id', 'ten_san_pham', 'hinh_anh',
        'gia_ban_co_ban', 'don_vi_tinh', 'trang_thai',
    ];

    protected $casts = [
        'gia_ban_co_ban' => 'decimal:2',
        'trang_thai'     => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }
}