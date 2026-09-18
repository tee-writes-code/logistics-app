<?php

declare(strict_types=1);

namespace App\Http\Requests\Demo;

use App\Services\ClockService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the demo clock preset. Ops + non-production access is enforced by the
 * `demo` middleware on the route group, so authorize() only re-affirms Ops.
 */
class SetClockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isOps() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'preset' => [
                'required',
                'string',
                Rule::in([ClockService::PRESET_BEFORE_CUTOFF, ClockService::PRESET_AFTER_CUTOFF]),
            ],
        ];
    }
}
