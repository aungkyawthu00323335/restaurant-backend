<?php

namespace App\Models;

use App\Traits\HasOutlet;



use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class IngredientStockMovement extends Model
{
    use HasFactory, HasOutlet;
    protected $outletColumn = 'location_id';

    use SoftDeletes;

    protected $fillable = [
        'ingredient_id',
        'product_id',
        'food_menu_id',
        'ingredient_batch_id',
        'location_id',
        'direction',
        'reason_code',
        'unit_type',
        'quantity_input',
        'quantity_consumption',
        'batch_unit_cost',
        'reference',
        'note',
        'occurred_at',
    ];

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function foodMenu(): BelongsTo
    {
        return $this->belongsTo(FoodMenu::class);
    }

    public function ingredientBatch(): BelongsTo
    {
        return $this->belongsTo(IngredientBatch::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    protected function casts(): array
    {
        return [
            'quantity_input' => 'decimal:4',
            'quantity_consumption' => 'decimal:4',
            'occurred_at' => 'datetime',
        ];
    }
}
