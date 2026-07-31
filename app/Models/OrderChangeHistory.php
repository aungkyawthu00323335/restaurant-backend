<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderChangeHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'action_type',
        'order_item_id',
        'old_qty',
        'new_qty',
        'changed_qty',
        'old_values',
        'new_values',
        'reason',
        'changed_by',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'old_qty' => 'decimal:4',
            'new_qty' => 'decimal:4',
            'changed_qty' => 'decimal:4',
            'old_values' => 'json',
            'new_values' => 'json',
            'changed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
