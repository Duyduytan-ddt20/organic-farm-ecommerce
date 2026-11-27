<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Batch extends Model
{
    use HasFactory;

    protected $fillable = ['product_id', 'batch_code', 'growing_location', 'planted_at', 'harvested_at', 'expires_at', 'received_quantity', 'available_quantity', 'status', 'qr_token'];

    protected $casts = ['planted_at' => 'date', 'harvested_at' => 'date', 'expires_at' => 'datetime', 'received_quantity' => 'decimal:2', 'available_quantity' => 'decimal:2'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function orderDetails(): HasMany
    {
        return $this->hasMany(OrderDetail::class);
    }

    public function farmingLogs(): HasMany
    {
        return $this->hasMany(FarmingLog::class);
    }

    public function stockDisposals(): HasMany
    {
        return $this->hasMany(StockDisposal::class);
    }
}
