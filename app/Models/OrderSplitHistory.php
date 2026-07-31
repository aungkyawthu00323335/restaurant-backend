<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderSplitHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_order_id',
        'target_order_id',
        'split_group_id',
        'split_by',
        'split_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'split_at' => 'datetime',
        ];
    }

    public function sourceOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'source_order_id');
    }

    public function targetOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'target_order_id');
    }

    public function splitBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'split_by');
    }

    public function splitItems(): HasMany
    {
        return $this->hasMany(OrderSplitItem::class, 'split_history_id');
    }
}
