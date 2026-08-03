<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RelocationCheck extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'done_at' => 'datetime',
        ];
    }

    public function relocation(): BelongsTo
    {
        return $this->belongsTo(Relocation::class);
    }
}
