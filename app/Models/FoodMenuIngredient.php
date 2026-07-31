<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FoodMenuIngredient extends Model
{
    use HasFactory;

    protected $fillable = [
        'food_menu_id',
        'ingredient_id',
        'unit_id',
        'required_qty',
        'unit_cost_snapshot',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'required_qty' => 'decimal:4',
            'unit_cost_snapshot' => 'decimal:4',
            'amount' => 'decimal:4',
        ];
    }

    public function foodMenu(): BelongsTo
    {
        return $this->belongsTo(FoodMenu::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ConsumptionUnit::class, 'unit_id');
    }
}
