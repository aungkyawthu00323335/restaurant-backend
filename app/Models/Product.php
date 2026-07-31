<?php

namespace App\Models;

use App\Traits\LogsActivity;
use App\Traits\NormalizesImageUrl;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, LogsActivity, NormalizesImageUrl;

    use SoftDeletes;

    protected static function booted(): void
    {
        static::addGlobalScope(new \App\Scopes\CatalogOutletScope);
    }

    protected $fillable = [
        'name',
        'code',
        'barcode',
        'product_category_id',
        'printer_id',
        'product_unit_id',
        'purchase_price_per_unit',
        'sell_price_per_unit',
        'low_stock_qty',
        'image_url',
        'description',
        'note',
        'is_active',
        'created_by_name',
        'updated_by_name',
    ];

    protected function casts(): array
    {
        return [
            'purchase_price_per_unit' => 'decimal:2',
            'sell_price_per_unit' => 'decimal:2',
            'low_stock_qty' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class, 'printer_id');
    }

    public function productUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'product_unit_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(ProductStockMovement::class);
    }

    public function currentStockForLocation(int $locationId): float
    {
        return round(
            (float) ProductStockMovement::query()
                ->where('product_id', $this->id)
                ->where('location_id', $locationId)
                ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(direction) = 'in' THEN quantity ELSE -quantity END), 0) AS net")
                ->value('net'),
            4
        );
    }

    public function locations(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'location_product')
            ->withPivot('sell_price_per_unit', 'is_active')
            ->withTimestamps();
    }
}
