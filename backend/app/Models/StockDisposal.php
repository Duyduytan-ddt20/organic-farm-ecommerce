<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockDisposal extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'batch_id',
        'staff_id',
        'quantity',
        'reason',
        'note',
        'image_path',
        'disposed_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'disposed_at' => 'datetime:Y-m-d H:i:s',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }
}
