<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Claim extends Model
{
    protected $fillable = [
        'order_item_id',
        'handled_by',
        'reason',
        'description',
        'claimed_quantity',
        'status',
        'resolution_type',
        'response_note',
        'submitted_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'claimed_quantity' => 'decimal:2',
            'submitted_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ClaimAttachment::class);
    }
}
