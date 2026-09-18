<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\FailReason;
use App\Models\Job;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FailRequest extends FormRequest
{
    public function authorize(): bool
    {
        $job = $this->route('job');

        return $job instanceof Job && ($this->user()?->can('execute', $job) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::enum(FailReason::class)],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
