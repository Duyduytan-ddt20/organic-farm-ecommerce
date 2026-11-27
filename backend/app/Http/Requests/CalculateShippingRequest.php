<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CalculateShippingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $address = $this->input('delivery_address');

        if (is_string($address)) {
            $normalized = preg_replace('/\s+/u', ' ', trim($address));
            if (is_string($normalized)) {
                $this->merge(['delivery_address' => $normalized]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'delivery_address' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'delivery_address.required' => 'Vui lòng nhập địa chỉ giao hàng.',
            'delivery_address.string' => 'Địa chỉ giao hàng không hợp lệ.',
            'delivery_address.max' => 'Địa chỉ giao hàng tối đa 500 ký tự.',
        ];
    }
}
