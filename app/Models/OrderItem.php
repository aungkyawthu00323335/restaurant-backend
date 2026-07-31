<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'item_type',
        'item_id',
        'item_name_snapshot',
        'unit_name_snapshot',
        'qty',
        'original_qty',
        'active_qty',
        'cancelled_qty',
        'printed_qty',
        'cancelled_printed_qty',
        'base_unit_price_snapshot',
        'modifier_price',
        'final_unit_price',
        'discount_type',
        'discount_value',
        'discount_amount',
        'amount',
        'item_note',
        'cost_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'original_qty' => 'decimal:4',
            'active_qty' => 'decimal:4',
            'cancelled_qty' => 'decimal:4',
            'printed_qty' => 'decimal:4',
            'cancelled_printed_qty' => 'decimal:4',
            'base_unit_price_snapshot' => 'decimal:4',
            'modifier_price' => 'decimal:4',
            'final_unit_price' => 'decimal:4',
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'amount' => 'decimal:2',
            'cost_snapshot' => 'decimal:4',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(OrderItemModifier::class);
    }

    public function comboComponents(): HasMany
    {
        return $this->hasMany(OrderComboComponent::class);
    }
}
