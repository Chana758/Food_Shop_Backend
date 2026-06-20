<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;

    // កំណត់ទិន្នន័យដែលអាចឱ្យបញ្ចូលបាន
    protected $fillable = ['name', 'slug', 'image', 'description'];

    // Relationship: មួយ Category មានច្រើន Products
    public function products()
    {
        return $this->hasMany(Product::class);
    }
}