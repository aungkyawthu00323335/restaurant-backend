<?php

namespace App\Models;

use App\Traits\LogsActivity;
use App\Traits\NormalizesImageUrl;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ingredient extends Model
{
    use HasFactory, LogsActivity, NormalizesImageUrl;
    use SoftDeletes;

    protected $fillable = [
        'type',
        'has_ingredient_mapping',
        'name',
        'ingredient_category_id',
        'purchase_unit_id',
        'consumption_unit_id',
        'conversion_rate',
        'purchase_price',
        'sku_code',
        'barcode',
        'description',
        'image_url',
        'initial_stock_data',
        'is_active',
    ];

    protected $appends = ['cost_per_consumption_unit'];

    public function getCostPerConsumptionUnitAttribute(): float
    {
        if ($this->has_ingredient_mapping || $this->type === 'composite') {
            return $this->compositions->sum(fn ($c) => $c->child->cost_per_consumption_unit * $c->quantity);
        }

        return $this->conversion_rate > 0
            ? round($this->purchase_price / $this->conversion_rate, 4)
            : 0;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(IngredientCategory::class, 'ingredient_category_id');
    }

    public function purchaseUnit(): BelongsTo
    {
        return $this->belongsTo(PurchaseUnit::class);
    }

    public function consumptionUnit(): BelongsTo
    {
        return $this->belongsTo(ConsumptionUnit::class);
    }

    public function compositions(): HasMany
    {
        return $this->hasMany(CompositeIngredient::class, 'ingredient_id');
    }

    public function processingOutputs(): HasMany
    {
        return $this->hasMany(IngredientProcessing::class, 'output_ingredient_id');
    }

    public function processingInputs(): HasMany
    {
        return $this->hasMany(IngredientProcessingDetail::class, 'input_ingredient_id');
    }

    public function foodMenuMappings(): HasMany
    {
        return $this->hasMany(FoodMenuIngredient::class);
    }

    protected function casts(): array
    {
        return [
            'conversion_rate' => 'decimal:4',
            'purchase_price' => 'decimal:2',
            'initial_stock_data' => 'array',
            'has_ingredient_mapping' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
