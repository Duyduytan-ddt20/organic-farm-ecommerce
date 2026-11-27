<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderIdempotencyKey extends Model
{
    protected $table = 'order_idempotency_keys';

    protected $fillable = [
        'user_id',
        'key_hash',
        'request_hash',
        'order_id',
        'response_status',
        'response_body',
    ];

    protected function casts(): array
    {
        return [
            'response_body' => 'array',
        ];
    }
}
