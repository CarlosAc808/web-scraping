<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Robot extends Model
{
    protected $fillable = [
        'name',
        'url',
        'interval_minutes',
        'output_format',
        'instructions',
        'monitor_enabled',
    ];

    protected function casts(): array
    {
        return [
            'monitor_enabled' => 'boolean',
            'interval_minutes' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(Execution::class);
    }
}
