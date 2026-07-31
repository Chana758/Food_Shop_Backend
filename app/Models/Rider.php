<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rider extends Model
{
    protected $fillable = ['name', 'phone', 'vehicle_type', 'status'];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}