<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; 

class Product extends Model
{
    use HasFactory, SoftDeletes; // Add SoftDeletes trait

    // Add new columns to $fillable
    protected $fillable = [
        'name',
        'slug',
        'price',
        'discount_price',
        'description',
        'image',
        'stock_quantity',
        'prep_time',     
        'sku',           
        'is_active',     
        'is_featured',  
        'category_id'
    ];

    // Relationship: A product belongs to a category
    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}