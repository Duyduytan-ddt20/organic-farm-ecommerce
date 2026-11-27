<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class StockDisposalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'batch_id' => $this->batch_id,
            'quantity' => number_format((float) $this->quantity, 2, '.', ''),
            'reason' => $this->reason,
            'note' => $this->note,
            'image_path' => $this->image_path,
            'image_url' => $this->image_path ? Storage::disk('public')->url($this->image_path) : null,
            'disposed_at' => $this->disposed_at?->format('Y-m-d H:i:s'),
            'available_quantity' => number_format((float) $this->batch?->available_quantity, 2, '.', ''),
            'status' => $this->batch?->status,
            'staff' => $this->staff ? [
                'id' => $this->staff->id,
                'full_name' => $this->staff->full_name,
            ] : null,
        ];
    }
}
