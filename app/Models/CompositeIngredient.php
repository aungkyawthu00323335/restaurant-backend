<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompositeIngredient extends Model
{
    protected $fillable = [
        'ingredient_id',
        'child_ingredient_id',
        'quantity',
        'unit_type',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
        ];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'ingredient_id');
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'child_ingredient_id');
    }
}
