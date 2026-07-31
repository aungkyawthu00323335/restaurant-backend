<?php

namespace App\Models;

use App\Traits\LogsActivity;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ComboMenu extends Model
{
    use HasFactory, LogsActivity;
    use SoftDeletes;

    public const ITEM_TYPES = ['food_menu', 'product'];

    protected static function booted(): void
    {
        static::addGlobalScope(new \App\Scopes\CatalogOutletScope);
    }

    protected $fillable = [
        'name',
        'code',
        'category_id',
        'dine_in_price',
        'take_away_price',
        'delivery_price',
        'cost_per_unit',
        'image_url',
        'description',
        'note',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'dine_in_price' => 'decimal:2',
            'take_away_price' => 'decimal:2',
            'delivery_price' => 'decimal:2',
            'cost_per_unit' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ComboMenuItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function locations(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'location_combo_menu')
            ->withPivot('dine_in_price', 'take_away_price', 'delivery_price', 'is_active')
            ->withTimestamps();
    }
}
