<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Job;
use Illuminate\Foundation\Http\FormRequest;

class UpdateJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        $job = $this->route('job');

        return $job instanceof Job && ($this->user()?->can('update', $job) ?? false);
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
