<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FarmingLog extends Model
{
    protected $fillable = [
        'batch_id', 'farm_staff_id', 'hanh_dong',
        'hinh_anh_minh_chung', 'thoi_gian', 'ghi_chu',
    ];

    protected $casts = [
        'thoi_gian' => 'datetime:Y-m-d H:i',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'farm_staff_id');
    }
}   