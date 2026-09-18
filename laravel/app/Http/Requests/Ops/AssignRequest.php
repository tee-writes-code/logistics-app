<?php

declare(strict_types=1);

namespace App\Http\Requests\Ops;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ops manually assigns a job to a specific rider (override of Dispatch).
 */
class AssignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('ops-console') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rider_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('role', UserRole::Rider->value),
            ],
        ];
    }
}
