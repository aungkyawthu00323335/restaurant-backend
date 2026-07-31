<?php

namespace App\Models;

use App\Traits\HasOutlet;



use App\Traits\LogsActivity;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FoodMenuProduction extends Model
{
    use HasFactory, LogsActivity, HasOutlet;
    protected $outletColumn = 'location_id';


    protected $fillable = [
        'ref_no',
        'location_id',
        'food_menu_id',
        'production_date',
        'production_qty',
        'unit_id',
        'total_ingredient_cost',
        'production_cost_per_unit',
        'status',
        'note',
        'created_by_name',
        'updated_by_name',
        'reversed_by_name',
        'reversed_at',
        'reverse_note',
    ];

    protected function casts(): array
    {
        return [
            'production_date' => 'date',
            'production_qty' => 'decimal:4',
            'total_ingredient_cost' => 'decimal:4',
            'production_cost_per_unit' => 'decimal:4',
            'reversed_at' => 'datetime',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function foodMenu(): BelongsTo
    {
        return $this->belongsTo(FoodMenu::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(FoodMenuUnit::class, 'unit_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(FoodMenuProductionDetail::class, 'production_id');
    }
}
