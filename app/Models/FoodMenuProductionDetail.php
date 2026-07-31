<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FoodMenuProductionDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'production_id',
        'ingredient_id',
        'ingredient_name_snapshot',
        'required_qty',
        'unit_id',
        'unit_name_snapshot',
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

    public function production(): BelongsTo
    {
        return $this->belongsTo(FoodMenuProduction::class, 'production_id');
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
