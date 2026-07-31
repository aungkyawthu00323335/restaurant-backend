<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemModifier extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_item_id',
        'modifier_group_id',
        'modifier_group_name_snapshot',
        'modifier_item_id',
        'modifier_item_name_snapshot',
        'price_adjustment_snapshot',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_adjustment_snapshot' => 'decimal:4',
        ];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
