<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Requests;

use App\Shared\Validation\BusinessDateRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the atomic onboarding command.
 *
 * Amounts are integer minor units and currencies are ISO-4217 codes, matching
 * the project-wide money rule (no floats anywhere in the request path).
 */
final class CreateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'person' => ['required', 'array'],
            'person.given_name' => ['required', 'string', 'max:120'],
            'person.family_name' => ['required', 'string', 'max:120'],
            'person.national_id' => ['nullable', 'string', 'max:64'],
            'person.id_type' => ['nullable', 'string', 'in:CN_ID,PASSPORT,OTHER'],
            'person.mobile' => ['nullable', 'string', 'max:32'],
            'person.email' => ['nullable', 'email', 'max:255'],
            'person.birth_date' => ['nullable', 'date'],

            'employment' => ['required', 'array'],
            'employment.employee_no' => ['required', 'string', 'max:64'],
            'employment.hire_date' => ['required', new BusinessDateRule],
            'employment.employment_type' => ['nullable', 'string', 'max:32'],

            'assignment' => ['required', 'array'],
            'assignment.org_unit_id' => ['required', 'integer', 'min:1'],
            'assignment.position_id' => ['nullable', 'integer', 'min:1'],
            'assignment.valid_from' => ['nullable', new BusinessDateRule],

            'terms' => ['required', 'array'],
            'terms.salary_minor' => ['required', 'integer', 'min:1'],
            'terms.currency' => ['required', 'string', 'size:3'],
            'terms.work_location' => ['nullable', 'string', 'max:120'],
            'terms.work_schedule' => ['nullable', 'string', 'max:64'],
            'terms.effective_from' => ['nullable', new BusinessDateRule],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employment.employee_no.required' => '员工编号为必填项。',
            'employment.hire_date.required' => '入职日期为必填项。',
            'assignment.org_unit_id.required' => '必须指定任职部门。',
            'terms.salary_minor.min' => '基本工资必须为正整数（最小货币单位）。',
            'terms.currency.size' => '币种必须是三位 ISO 代码。',
        ];
    }
}
