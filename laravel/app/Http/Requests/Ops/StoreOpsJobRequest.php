<?php

declare(strict_types=1);

namespace App\Http\Requests\Ops;

use App\Enums\JobWindow;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ops creates a job on behalf of a customer. The pickup site must belong to the
 * named customer; the rest mirrors the customer booking payload.
 */
class StoreOpsJobRequest extends FormRequest
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
            'customer_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('role', UserRole::Customer->value),
            ],
            'pickup_site_id' => [
                'required',
                'integer',
                Rule::exists('pickup_sites', 'id')->where('user_id', $this->integer('customer_id')),
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
        ];
    }
}
