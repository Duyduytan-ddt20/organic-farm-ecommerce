<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStockDisposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merged = [];

        if (is_string($this->input('quantity'))) {
            $merged['quantity'] = trim($this->input('quantity'));
        }

        foreach (['note', 'disposed_at'] as $field) {
            if ($this->input($field) === '') {
                $merged[$field] = null;
            }
        }

        if ($merged !== []) {
            $this->merge($merged);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,2})?$/'],
            'reason' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
            'disposed_at' => ['nullable', 'date'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.required' => 'Vui lòng nhập số lượng xuất hủy.',
            'quantity.numeric' => 'Số lượng xuất hủy phải là số.',
            'quantity.gt' => 'Số lượng xuất hủy phải lớn hơn 0.',
            'quantity.regex' => 'Số lượng xuất hủy chỉ được tối đa hai chữ số thập phân.',
            'reason.required' => 'Vui lòng nhập nguyên nhân.',
            'reason.max' => 'Nguyên nhân không được vượt quá 255 ký tự.',
            'note.string' => 'Ghi chú không hợp lệ.',
            'disposed_at.date' => 'Thời điểm xuất hủy không hợp lệ.',
        ];
    }
}
