<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TableMergeMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'merge_group_id',
        'table_id',
        'member_type',
        'original_status',
        'active_status',
    ];

    public function mergeGroup(): BelongsTo
    {
        return $this->belongsTo(TableMergeGroup::class, 'merge_group_id');
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id');
    }
}
