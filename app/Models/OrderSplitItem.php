<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderSplitItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'split_history_id',
        'source_order_item_id',
        'target_order_item_id',
        'moved_qty',
        'amount',
        'discount_amount',
    ];

    protected function casts(): array
    {
        return [
            'moved_qty' => 'decimal:4',
            'amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
        ];
    }

    public function splitHistory(): BelongsTo
    {
        return $this->belongsTo(OrderSplitHistory::class, 'split_history_id');
    }

    public function sourceOrderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'source_order_item_id');
    }

    public function targetOrderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'target_order_item_id');
    }
}
