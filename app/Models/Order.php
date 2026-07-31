<?php

namespace App\Models;

use App\Traits\HasOutlet;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Traits\LogsActivity;

class Order extends Model
{
    use HasFactory, LogsActivity, HasOutlet;

    protected $fillable = [
        'order_no',
        'parent_order_id',
        'split_group_id',
        'split_sequence',
        'split_from_order_id',
        'table_merge_group_id',
        'outlet_id',
        'order_type',
        'floor_id',
        'table_id',
        'pax',
        'customer_name',
        'customer_phone',
        'pickup_time',
        'delivery_partner',
        'delivery_address',
        'delivery_fee',
        'order_note',
        'subtotal',
        'item_discount_amount',
        'order_discount_type',
        'order_discount_value',
        'order_discount_amount',
        'tax_rate_snapshot',
        'tax_amount',
        'service_charge_rate_snapshot',
        'service_charge_amount',
        'grand_total',
        'paid_amount',
        'balance_amount',
        'change_amount',
        'order_status',
        'confirmation_status',
        'confirmed_at',
        'confirmed_by',
        'print_status',
        'stock_deduction_status',
        'stock_deducted_at',
        'payment_completed_at',
        'payment_state',
        'version_number',
        'created_by',
        'updated_by',
        'sale_id',
        'completed_at',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'pax' => 'integer',
            'delivery_fee' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'item_discount_amount' => 'decimal:2',
            'order_discount_value' => 'decimal:2',
            'order_discount_amount' => 'decimal:2',
            'tax_rate_snapshot' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'service_charge_rate_snapshot' => 'decimal:2',
            'service_charge_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'balance_amount' => 'decimal:2',
            'change_amount' => 'decimal:2',
            'pickup_time' => 'datetime',
            'stock_deducted_at' => 'datetime',
            'payment_completed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'version_number' => 'integer',
            'split_sequence' => 'integer',
        ];
    }

    public function parentOrder(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_order_id');
    }

    public function childOrders(): HasMany
    {
        return $this->hasMany(self::class, 'parent_order_id');
    }

    public function splitFromOrder(): BelongsTo
    {
        return $this->belongsTo(self::class, 'split_from_order_id');
    }

    public function tableMergeGroup(): BelongsTo
    {
        return $this->belongsTo(TableMergeGroup::class, 'table_merge_group_id');
    }

    public function changeHistories(): HasMany
    {
        return $this->hasMany(OrderChangeHistory::class);
    }

    public function splitHistories(): HasMany
    {
        return $this->hasMany(OrderSplitHistory::class, 'source_order_id');
    }

    public function printLogs(): HasMany
    {
        return $this->hasMany(PrintLog::class);
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

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function sale(): HasOne
    {
        return $this->hasOne(Sale::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
