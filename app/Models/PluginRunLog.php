<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PluginRunLog extends Model
{
    use HasFactory;

    protected $table = 'extension_plugin_run_logs';

    protected $casts = [
        'context' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(PluginRun::class, 'extension_plugin_run_id');
    }
}
