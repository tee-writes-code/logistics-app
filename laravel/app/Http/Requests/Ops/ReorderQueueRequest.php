<?php

declare(strict_types=1);

namespace App\Http\Requests\Ops;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Ops reorders a rider's queue. The body is the full ordered list of the rider's
 * live job ids; the Dispatch agent validates membership and the active-first rule.
 */
class ReorderQueueRequest extends FormRequest
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
            'job_ids' => ['required', 'array', 'min:1'],
            'job_ids.*' => ['integer', 'distinct'],
        ];
    }
}
