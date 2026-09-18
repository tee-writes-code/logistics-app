<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\SavedDrop;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSavedDropRequest extends FormRequest
{
    public function authorize(): bool
    {
        $drop = $this->route('savedDrop');

        return $drop instanceof SavedDrop && $drop->user_id === $this->user()?->id;
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
            'phone' => ['nullable', 'string', 'max:40'],
        ];
    }
}
