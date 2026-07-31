<?php

namespace App\Models;

use App\Traits\HasOutlet;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\LogsActivity;

class Expense extends Model
{
    use HasFactory, SoftDeletes, LogsActivity, HasOutlet;

    protected $fillable = [
        'expense_category_id',
        'outlet_id',
        'date',
        'amount',
        'reference_no',
        'note',
        'attachment',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'float',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'outlet_id');
    }
}
