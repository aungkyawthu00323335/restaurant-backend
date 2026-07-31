<?php

namespace App\Models;

use App\Traits\HasOutlet;



use App\Traits\LogsActivity;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IngredientProcessing extends Model
{
    use HasFactory, LogsActivity, HasOutlet;
    protected $outletColumn = 'location_id';


    protected $fillable = [
        'ref_no',
        'processing_date',
        'location_id',
        'output_ingredient_id',
        'output_ingredient_name',
        'processing_qty',
        'output_unit',
        'total_input_cost',
        'output_unit_cost',
        'status',
        'note',
        'created_by',
        'created_by_name',
        'updated_by',
        'updated_by_name',
        'reversed_by',
        'reversed_by_name',
        'reversed_at',
        'reverse_note',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function outputIngredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'output_ingredient_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(IngredientProcessingDetail::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    protected function casts(): array
    {
        return [
            'processing_date' => 'date',
            'processing_qty' => 'decimal:4',
            'total_input_cost' => 'decimal:4',
            'output_unit_cost' => 'decimal:4',
            'reversed_at' => 'datetime',
        ];
    }
}
