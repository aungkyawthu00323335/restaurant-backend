<?php

namespace App\Models;

use App\Traits\HasOutlet;



use App\Traits\LogsActivity;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Floor extends Model
{
    use HasFactory, SoftDeletes, LogsActivity, HasOutlet;
    protected $outletColumn = 'location_id';


    protected $fillable = [
        'name',
        'code',
        'location_id',
        'description',
        'sort_order',
        'is_active',
        'note',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function tables(): HasMany
    {
        return $this->hasMany(RestaurantTable::class, 'floor_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'floor_id');
    }
}
