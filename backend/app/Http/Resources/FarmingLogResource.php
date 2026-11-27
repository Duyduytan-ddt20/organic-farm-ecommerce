<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class FarmingLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'batch_id' => $this->batch_id,
            'activity' => $this->activity,
            'description' => $this->description,
            'image_path' => $this->image_path,
            'image_url' => $this->image_path ? Storage::disk('public')->url($this->image_path) : null,
            'performed_at' => $this->performed_at?->format('Y-m-d H:i:s'),
            'staff' => $this->staff ? [
                'id' => $this->staff->id,
                'full_name' => $this->staff->full_name,
            ] : null,
        ];
    }
}
