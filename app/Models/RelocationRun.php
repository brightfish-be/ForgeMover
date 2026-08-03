<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RelocationRun extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function relocation(): BelongsTo
    {
        return $this->belongsTo(Relocation::class);
    }

    public function logTail(int $maxBytes = 65536): string
    {
        if (! is_file($this->log_file)) {
            return '';
        }
        $size = filesize($this->log_file);
        $handle = fopen($this->log_file, 'rb');
        if ($size > $maxBytes) {
            fseek($handle, $size - $maxBytes);
        }
        $tail = stream_get_contents($handle);
        fclose($handle);

        return $tail === false ? '' : $tail;
    }
}
