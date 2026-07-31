<?php

namespace App\Models;

use App\Traits\LogsActivity;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Printer extends Model
{
    use HasFactory, LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'ip_address',
        'port',
        'paper_size',
        'copies',
        'is_active',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'copies' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function foodMenus(): HasMany
    {
        return $this->hasMany(FoodMenu::class);
    }
}
