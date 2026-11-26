<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnalyzeStatementRequest extends FormRequest
{
    public const SUPPORTED_BANKS = ['ttb', 'kbank', 'scb', 'bbl', 'ktb', 'bay'];
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'bank_code' => ['required', 'string', Rule::in(self::SUPPORTED_BANKS)],
            'file' => ['required', 'file', 'mimetypes:application/pdf', 'max:10240'],
            'password' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bank_code.required' => 'กรุณาเลือกธนาคาร',
            'bank_code.in' => 'ธนาคารที่เลือกไม่ถูกต้อง',
            'file.required' => 'กรุณาแนบไฟล์ Statement',
            'file.file' => 'รูปแบบไฟล์ที่อัปโหลดไม่ถูกต้อง',
            'file.mimetypes' => 'รองรับเฉพาะไฟล์ PDF เท่านั้น',
            'file.max' => 'ขนาดไฟล์ต้องไม่เกิน 10MB',
            'password.string' => 'รหัสผ่านต้องเป็นข้อความเท่านั้น',
            'password.max' => 'รหัสผ่านต้องมีความยาวไม่เกิน 50 ตัวอักษร',
        ];
    }
}
