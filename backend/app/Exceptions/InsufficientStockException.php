<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly float $requested,
        public readonly float $available,
        public readonly string $productName = 'Sản phẩm',
    ) {
        parent::__construct("Tồn kho hợp lệ của {$productName} không đủ.");
    }
}
