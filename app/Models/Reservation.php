<?php

namespace App\Models;

use App\Traits\HasOutlet;

use App\Traits\LogsActivity;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Reservation extends Model
{
    use HasFactory, SoftDeletes, LogsActivity, HasOutlet;

    protected $fillable = [
        'reservation_no',
        'outlet_id',
        'floor_id',
        'table_id',
        'customer_name',
        'customer_phone',
        'guest_count',
        'reservation_date',
        'checkin_time',
        'special_request',
        'has_preorder',
        'preorder_items',
        'status',
        'created_by',
        'updated_by',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'guest_count' => 'integer',
            'reservation_date' => 'date',
            'checkin_time' => 'string',
            'has_preorder' => 'boolean',
            'preorder_items' => 'array',
            'cancelled_at' => 'datetime',
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

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id');
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
