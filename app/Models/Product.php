<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'price',
        'discount_price',
        'discount_expires_at',
        'description',
        'image',
        'stock_quantity',
        'prep_time',
        'sku',
        'is_active',
        'is_featured',
        'category_id',
    ];

    protected $casts = [
        'discount_expires_at' => 'datetime',
        'is_active'           => 'boolean',
        'is_featured'         => 'boolean',
        'price'               => 'decimal:2',
        'discount_price'      => 'decimal:2',
        'stock_quantity'      => 'integer',
        'prep_time'           => 'integer',
    ];

    //  Auto-included in JSON responses (so frontend doesn't need to recompute)
    protected $appends = ['has_active_discount', 'final_price'];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * True if discount_price is valid AND not expired.
     */
    public function getHasActiveDiscountAttribute(): bool
    {
        if (!$this->discount_price || $this->discount_price >= $this->price) {
            return false;
        }
        if ($this->discount_expires_at && $this->discount_expires_at->isPast()) {
            return false;
        }
        return true;
    }

    /**
     * The price the customer actually pays right now.
     */
    public function getFinalPriceAttribute(): float
    {
        return $this->has_active_discount
            ? (float) $this->discount_price
            : (float) $this->price;
    }
}