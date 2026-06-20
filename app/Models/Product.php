<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // ១. ត្រូវ import ចូល

class Product extends Model
{
    use HasFactory, SoftDeletes; // ២. ត្រូវ add trait SoftDeletes ចូល

    // ៣. បន្ថែម Column ថ្មីៗចូលទៅក្នុង $fillable
    protected $fillable = [
        'name',
        'slug',
        'price',
        'discount_price', // បន្ថែមថ្មី
        'description',
        'image',
        'stock_quantity',
        'prep_time',      // បន្ថែមថ្មី
        'sku',            // បន្ថែមថ្មី
        'is_active',      // បន្ថែមថ្មី
        'is_featured',    // បន្ថែមថ្មី
        'category_id'
    ];

    // Relationship: មួយ Product ជារបស់មួយ Category
    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}