<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\PickupSite;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePickupSiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $site = $this->route('pickupSite');

        return $site instanceof PickupSite && $site->user_id === $this->user()?->id;
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
