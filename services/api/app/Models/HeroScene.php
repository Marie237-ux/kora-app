<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class HeroScene extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'draft' => 'array',
            'publication' => 'array',
            'paused' => 'boolean',
            'version' => 'integer',
            'published_at' => 'datetime',
        ];
    }
}
