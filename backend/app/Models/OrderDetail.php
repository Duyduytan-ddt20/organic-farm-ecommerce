<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDetail extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'order_item_id',
        'batch_id',
        'quantity',
        'allocated_at',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'allocated_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
