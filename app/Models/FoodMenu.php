<?php

namespace App\Models;

use App\Traits\LogsActivity;
use App\Traits\NormalizesImageUrl;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FoodMenu extends Model
{
    use HasFactory, LogsActivity, NormalizesImageUrl;
    use SoftDeletes;

    public const STOCK_DEDUCTION_METHODS = [
        'no_stock',
        'deduct_ingredient_on_sale',
        'production_stock',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new \App\Scopes\CatalogOutletScope);
    }

    protected $fillable = [
        'category_id',
        'printer_id',
        'unit_id',
        'name',
        'code',
        'stock_deduction_method',
        'dine_in_price',
        'take_away_price',
        'delivery_price',
        'cost_per_unit',
        'current_stock_qty',
        'low_stock_qty',
        'image_url',
        'description',
        'note',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'dine_in_price' => 'decimal:2',
            'take_away_price' => 'decimal:2',
            'delivery_price' => 'decimal:2',
            'cost_per_unit' => 'decimal:4',
            'current_stock_qty' => 'decimal:4',
            'low_stock_qty' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(FoodMenuUnit::class, 'unit_id');
    }

    public function ingredientMappings(): HasMany
    {
        return $this->hasMany(FoodMenuIngredient::class);
    }

    public function modifierGroups(): BelongsToMany
    {
        return $this->belongsToMany(
            Modifier::class,
            'food_menu_modifier_groups',
            'food_menu_id',
            'modifier_group_id'
        )->withPivot(['is_required', 'min_selection', 'max_selection', 'sort_order'])
            ->withTimestamps();
    }

    public function getStockStatusAttribute(): string
    {
        if ($this->stock_deduction_method !== 'production_stock') {
            return 'not_tracked';
        }

        $quantity = (float) $this->current_stock_qty;
        $lowStock = (float) ($this->low_stock_qty ?? 0);

        if ($quantity <= 0) {
            return 'out_of_stock';
        }

        return $lowStock > 0 && $quantity <= $lowStock ? 'low_stock' : 'in_stock';
    }

    public function locations(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'location_food_menu')
            ->withPivot('dine_in_price', 'take_away_price', 'delivery_price', 'is_active')
            ->withTimestamps();
    }
}
