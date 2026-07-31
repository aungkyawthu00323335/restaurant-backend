<?php

namespace App\Models;

use App\Traits\LogsActivity;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'number',
        'email',
        'phone',
        'birthday',
        'address',
        'city',
        'state',
        'postal_code',
        'country',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'birthday' => 'date',
        ];
    }
}
