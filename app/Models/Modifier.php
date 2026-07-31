<?php

namespace App\Models;

use App\Traits\LogsActivity;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Modifier extends Model
{
    use HasFactory, LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'selection_type',
        'min_selection',
        'max_selection',
        'is_required',
        'options',
        'is_active',
        'sort_order',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'min_selection' => 'integer',
            'max_selection' => 'integer',
            'is_required' => 'boolean',
            'options' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function foodMenus(): BelongsToMany
    {
        return $this->belongsToMany(
            FoodMenu::class,
            'food_menu_modifier_groups',
            'modifier_group_id',
            'food_menu_id'
        )->withPivot(['min_selection', 'max_selection', 'is_required', 'sort_order'])
            ->withTimestamps();
    }
}
