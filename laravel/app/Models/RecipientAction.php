<?php

namespace App\Models;

use Database\Factories\RecipientActionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recipient action taken via the magic link. `type` is one of:
 * delivery_instruction | hold | receive_confirm | refuse.
 */
#[Fillable(['job_id', 'type', 'note', 'meta'])]
class RecipientAction extends Model
{
    /** @use HasFactory<RecipientActionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Job, $this>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }
}
