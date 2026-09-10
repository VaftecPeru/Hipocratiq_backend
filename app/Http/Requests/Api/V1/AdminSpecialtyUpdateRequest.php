<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Specialty;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdminSpecialtyUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9_-]*$/', Rule::unique('specialties', 'code')->ignore($this->route('specialty'))],
            'name' => ['sometimes', 'string', 'min:2', 'max:160'],
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('specialties', 'id')->whereNull('parent_id')],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $specialty = $this->route('specialty');
            $parentId = $this->input('parent_id');

            if ($specialty instanceof Specialty && $parentId !== null && (int) $parentId === $specialty->getKey()) {
                $validator->errors()->add('parent_id', 'Una especialidad no puede ser su propio padre.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $input = [];

        if ($this->has('code')) {
            $input['code'] = is_string($this->input('code')) ? strtolower(trim($this->input('code'))) : $this->input('code');
        }

        if ($this->has('name')) {
            $input['name'] = is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name');
        }

        $this->merge($input);
    }
}
