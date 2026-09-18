<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Job;
use Illuminate\Foundation\Http\FormRequest;

class DeliverRequest extends FormRequest
{
    public function authorize(): bool
    {
        $job = $this->route('job');

        return $job instanceof Job && ($this->user()?->can('execute', $job) ?? false);
    }

    /**
     * A signature is required to deliver; the POD photo is optional (spec
     * override: delivery needs a signature only).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'signature' => ['required', 'string'],
            'photo' => ['nullable', 'string'],
        ];
    }
}
