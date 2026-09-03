<?php

namespace App\Http\Requests\Admin;

use App\Enums\Role;
use App\Services\StaffOrganizationalPlacementService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware already restricts this to sysadmin.
        return true;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', 'required_without:role_id', Rule::enum(Role::class)],
            'role_id' => ['nullable', 'required_without:role', 'integer', Rule::exists('roles', 'id')->where('is_active', true)],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'division_id' => [
                'nullable', 'integer',
                Rule::exists('divisions', 'id')->where(fn ($query) => $query
                    ->where('department_id', $this->input('department_id'))
                    ->where('active', true)
                    ->whereNull('deleted_at')),
            ],
            'organizational_unit_id' => [
                'nullable', 'integer',
                Rule::exists('organizational_units', 'id')->where(fn ($query) => $query
                    ->where('active', true)
                    ->whereNull('deleted_at')
                    ->whereIn('type', StaffOrganizationalPlacementService::assignableTypeValues())),
            ],
            'position_id' => [
                'nullable', 'integer',
                Rule::exists('positions', 'id')->where(fn ($query) => $query
                    ->where('organizational_unit_id', $this->input('organizational_unit_id'))
                    ->where('active', true)
                    ->whereNull('deleted_at')),
            ],
            'employee_number' => ['nullable', 'string', 'max:80', Rule::unique('users', 'employee_number')],
            'email' => ['nullable', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'username' => [
                'required', 'string', 'max:60',
                'regex:/^[a-z0-9._-]+$/',
                Rule::unique('users', 'username'),
            ],
        ];
    }

    /**
     * Login identifiers are matched case-insensitively and without
     * surrounding whitespace (AUTH-010), so store them normalized.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => $this->filled('email') ? mb_strtolower(trim((string) $this->input('email'))) : null,
            'employee_number' => $this->filled('employee_number') ? trim((string) $this->input('employee_number')) : null,
            'username' => trim((string) $this->input('username')),
        ]);
    }

    public function messages(): array
    {
        return [
            'username.unique' => 'This username is already taken — choose another.',
            'employee_number.unique' => 'This staff ID is already registered to another account.',
            'email.unique' => 'This email address is already registered to another account.',
            'username.regex' => 'Usernames may only contain lowercase letters, numbers, dots, hyphens, and underscores.',
            'division_id.exists' => 'Select an active division belonging to the selected department.',
            'organizational_unit_id.exists' => 'Select an active internal organizational entity.',
            'position_id.exists' => 'Select an approved position belonging to the selected unit.',
        ];
    }
}
