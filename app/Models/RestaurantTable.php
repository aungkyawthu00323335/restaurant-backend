<?php

namespace App\Models;

use App\Traits\HasOutlet;

use App\Traits\LogsActivity;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RestaurantTable extends Model
{
    use HasFactory, SoftDeletes, LogsActivity, HasOutlet;

    protected $table = 'tables';

    protected $fillable = [
        'outlet_id',
        'floor_id',
        'table_no',
        'code',
        'capacity',
        'shape',
        'sort_order',
        'status',
        'merged_with_table_id',
        'description',
        'note',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'outlet_id');
    }

    public function mergedWith(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_with_table_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'table_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
