<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\JobWindow;
use App\Models\Job;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Job::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'pickup_site_id' => [
                'required',
                'integer',
                Rule::exists('pickup_sites', 'id')->where('user_id', $this->user()?->id),
            ],
            'drop_address' => ['required', 'string', 'max:500'],
            'drop_contact_name' => ['required', 'string', 'max:120'],
            'drop_phone' => ['required', 'string', 'max:40'],
            'part_line' => ['required', 'array'],
            'part_line.name' => ['required', 'string', 'max:120'],
            'part_line.sku' => ['nullable', 'string', 'max:60'],
            'part_line.qty' => ['required', 'integer', 'min:1', 'max:1000'],
            'part_line.serial' => ['nullable', 'string', 'max:120'],
            'window' => ['required', Rule::enum(JobWindow::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'offer_next_accepted' => ['nullable', 'boolean'],
            'save_drop' => ['nullable', 'boolean'],
            'save_drop_label' => ['nullable', 'string', 'max:120', 'required_if:save_drop,true'],
        ];
    }
}
