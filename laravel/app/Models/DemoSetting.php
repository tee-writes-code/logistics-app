<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A demo-only persisted setting (iter-5). Currently the single row keyed
 * "clock_override" carries the advancing clock offset ClockService reads. The
 * `value` column is JSON, so each setting stores whatever shape it needs.
 */
#[Fillable(['key', 'value'])]
class DemoSetting extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }
}
