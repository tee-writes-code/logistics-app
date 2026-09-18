<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Job;
use Illuminate\Foundation\Http\FormRequest;

class ReturnCompleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $job = $this->route('job');

        return $job instanceof Job && ($this->user()?->can('execute', $job) ?? false);
    }

    /**
     * The return photo is optional (spec override): a return can complete with
     * no photo row.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'return_photo' => ['nullable', 'string'],
        ];
    }
}
