<?php

declare(strict_types=1);

namespace App\Http\Requests\Ops;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Ops edits a job on behalf of the customer. Same editable fields as the customer
 * update; Ops is not bound by the customer's stage gates.
 */
class UpdateOpsJobRequest extends FormRequest
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
            'drop_address' => ['sometimes', 'required', 'string', 'max:500'],
            'drop_contact_name' => ['sometimes', 'required', 'string', 'max:120'],
            'drop_phone' => ['sometimes', 'required', 'string', 'max:40'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
