<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Order extends Model
{
    protected $fillable = ['user_id', 'confirmed_by', 'order_number', 'customer_name', 'customer_email', 'customer_phone', 'shipping_address', 'status', 'total_amount', 'confirmed_at'];

    protected $casts = ['total_amount' => 'decimal:2', 'confirmed_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
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
