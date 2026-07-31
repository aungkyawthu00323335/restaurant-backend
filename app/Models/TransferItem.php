<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'transfer_id',
        'item_type',
        'item_id',
        'unit_id',
        'unit_type',
        'quantity',
        'unit_cost',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    // Since we store polymorphic manually, we can define direct accessors:

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'item_id');
    }

    public function foodMenu(): BelongsTo
    {
        return $this->belongsTo(FoodMenu::class, 'item_id');
    }

    /**
     * Helper to get the actual item instance
     */
    public function getItemAttribute()
    {
        if ($this->item_type === 'ingredient') {
            return $this->ingredient;
        } elseif ($this->item_type === 'product') {
            return $this->product;
        } elseif ($this->item_type === 'food_menu') {
            return $this->foodMenu;
        }
        return null;
    }
}
