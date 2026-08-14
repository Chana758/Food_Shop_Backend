<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'table_id',
        'rider_id',
        'order_type',
        'status',
        'total_amount',
        //  FIX: was missing from $fillable. OrderController::store()
        // now mass-assigns 'discount_amount' via Order::create([...]) —
        // without this, Laravel silently drops the field (mass-assignment
        // protection) and every order would be saved with discount_amount
        // = 0 regardless of what the POS staff actually applied, even
        // though total_amount itself would still be correctly discounted.
        // Keeping discount_amount accurate matters for reporting/receipts
        // that need to show "subtotal - discount = total" separately.
        'discount_amount',
        'notes',
        // Delivery fields
        'customer_name',
        'customer_phone',
        'delivery_address',
        'delivery_status',
        'delivery_proof',
    ];

    // ── Relationships ──────────────────────────────────────
    public function user()       { return $this->belongsTo(User::class); }
    public function table()      { return $this->belongsTo(Table::class); }
    public function rider()      { return $this->belongsTo(Rider::class); }
    public function payment()    { return $this->hasOne(Payment::class); }

    // items() — used by OrderController
    // orderItems() — kept for backward compatibility
    public function items()      { return $this->hasMany(OrderItem::class); }
    public function orderItems() { return $this->hasMany(OrderItem::class); }
}