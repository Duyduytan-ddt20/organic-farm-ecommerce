<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FarmingLog extends Model
{
    protected $fillable = [
        'batch_id',
        'staff_id',
        'activity',
        'description',
        'image_path',
        'performed_at',
    ];

    protected function casts(): array
    {
        return [
            'performed_at' => 'datetime:Y-m-d H:i:s',
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
