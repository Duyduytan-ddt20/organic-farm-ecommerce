<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Order extends Model
{
    protected $fillable = ['user_id', 'order_code', 'order_type', 'recipient_name', 'recipient_phone', 'delivery_address', 'shipping_fee', 'total_amount', 'status', 'confirmed_at', 'delivered_at', 'cancelled_at'];

    protected $casts = ['shipping_fee' => 'decimal:2', 'total_amount' => 'decimal:2', 'confirmed_at' => 'datetime', 'delivered_at' => 'datetime', 'cancelled_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function allocations(): HasManyThrough
    {
        return $this->hasManyThrough(OrderDetail::class, OrderItem::class);
    }
}
