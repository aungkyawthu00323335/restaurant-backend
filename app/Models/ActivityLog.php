<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'outlet_id',
        'action',
        'module',
        'reference_type',
        'reference_id',
        'reason',
        'request_id',
        'created_at',
    ];

    protected $casts = [
            'created_at' => 'datetime',
            'reference_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
