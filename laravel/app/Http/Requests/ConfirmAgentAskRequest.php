<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Confirm an agent ask. An optional decision overrides the ask's default plan for
 * a fail/refuse ask (reattempt | next | return); it is ignored for other asks.
 */
class ConfirmAgentAskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['nullable', Rule::in(['reattempt', 'next', 'return'])],
        ];
    }
}
