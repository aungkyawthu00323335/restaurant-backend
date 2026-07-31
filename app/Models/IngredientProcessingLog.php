<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IngredientProcessingLog extends Model
{
    use HasFactory;

    protected $table = 'ingredient_processing_logs';

    protected $fillable = [
        'ingredient_id',
        'order_id',
        'processed_qty',
        'stock_before',
        'stock_after',
        'user_id',
        'processed_at',
    ];

    protected $dates = ['processed_at'];

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
?>
