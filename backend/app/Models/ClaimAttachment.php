<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClaimAttachment extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'claim_id',
        'file_path',
        'original_name',
        'mime_type',
        'file_size_bytes',
        'created_at',
    ];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }
}
