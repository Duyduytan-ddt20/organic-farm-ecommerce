<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ClaimAttachment extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'claim_id',
        'file_path',
        'original_name',
        'mime_type',
        'file_size_bytes',
    ];

    protected $appends = ['url'];

    protected $casts = [
        'file_size_bytes' => 'integer',
    ];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->file_path);
    }
}
