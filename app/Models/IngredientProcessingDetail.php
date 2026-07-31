<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngredientProcessingDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'ingredient_processing_id',
        'input_ingredient_id',
        'input_ingredient_name',
        'input_qty',
        'input_qty_consumption',
        'input_unit',
        'input_unit_type',
        'input_unit_cost',
        'input_amount',
    ];

    public function processing(): BelongsTo
    {
        return $this->belongsTo(IngredientProcessing::class, 'ingredient_processing_id');
    }

    public function inputIngredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'input_ingredient_id');
    }

    protected function casts(): array
    {
        return [
            'input_qty' => 'decimal:4',
            'input_qty_consumption' => 'decimal:4',
            'input_unit_cost' => 'decimal:4',
            'input_amount' => 'decimal:4',
        ];
    }
}
