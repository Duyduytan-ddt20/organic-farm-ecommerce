<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFarmingLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('description') === '') {
            $this->merge(['description' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'activity' => ['required', 'string', Rule::in(['Gieo trồng', 'Chăm sóc', 'Thu hoạch'])],
            'description' => ['nullable', 'string'],
            'performed_at' => ['required', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'activity.required' => 'Vui lòng chọn hoạt động.',
            'activity.in' => 'Hoạt động chỉ được là Gieo trồng, Chăm sóc hoặc Thu hoạch.',
            'description.string' => 'Mô tả không hợp lệ.',
            'performed_at.required' => 'Vui lòng nhập thời điểm thực hiện.',
            'performed_at.date' => 'Thời điểm thực hiện không hợp lệ.',
        ];
    }
}
