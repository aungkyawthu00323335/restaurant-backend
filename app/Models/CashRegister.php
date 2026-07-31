<?php

namespace App\Models;

use App\Traits\HasOutlet;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashRegister extends Model
{
    use HasFactory, HasOutlet;

    protected $fillable = [
        'outlet_id', 'cashier_id', 'cashier_name_snapshot', 'opened_at',
        'opening_balance', 'opening_note', 'cash_sale_amount',
        'other_payment_amount', 'closed_at', 'expected_closing_balance',
        'actual_closing_balance', 'difference_amount', 'closing_note', 'status',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_balance' => 'decimal:2',
            'cash_sale_amount' => 'decimal:2',
            'other_payment_amount' => 'decimal:2',
            'expected_closing_balance' => 'decimal:2',
            'actual_closing_balance' => 'decimal:2',
            'difference_amount' => 'decimal:2',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'outlet_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }
}
