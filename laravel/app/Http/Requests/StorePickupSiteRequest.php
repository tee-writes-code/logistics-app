<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class StorePickupSiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Customer;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:120'],
            'address' => ['required', 'string', 'max:500'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
        ];
    }
}
