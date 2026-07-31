<?php

namespace App\Models;

use App\Traits\HasOutlet;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TableMergeGroup extends Model
{
    use HasFactory, HasOutlet;

    protected $fillable = [
        'outlet_id',
        'floor_id',
        'primary_table_id',
        'status',
        'merged_by',
        'merged_at',
        'unmerged_by',
        'unmerged_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'merged_at' => 'datetime',
            'unmerged_at' => 'datetime',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'outlet_id');
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    public function primaryTable(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'primary_table_id');
    }

    public function mergedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merged_by');
    }

    public function unmergedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unmerged_by');
    }

    public function members(): HasMany
    {
        return $this->hasMany(TableMergeMember::class, 'merge_group_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'table_merge_group_id');
    }
}
