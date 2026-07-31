<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id', 'item_type', 'item_id', 'item_name_snapshot', 'unit_name_snapshot',
        'qty', 'base_unit_price_snapshot', 'modifier_price_snapshot', 'final_unit_price_snapshot',
        'discount_amount', 'amount', 'cost_snapshot', 'item_note_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'base_unit_price_snapshot' => 'decimal:4',
            'modifier_price_snapshot' => 'decimal:4',
            'final_unit_price_snapshot' => 'decimal:4',
            'discount_amount' => 'decimal:2',
            'amount' => 'decimal:2',
            'cost_snapshot' => 'decimal:4',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(SaleModifier::class, 'sale_item_id');
    }
}
