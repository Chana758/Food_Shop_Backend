<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    use HasFactory;

    // Common types. 'type' is a plain string, so new ones need no migration.
    public const TYPE_ORDER       = 'order';
    public const TYPE_PAYMENT     = 'payment';
    public const TYPE_RESERVATION = 'reservation';
    public const TYPE_PROMO       = 'promo';
    public const TYPE_GENERAL     = 'general';

    protected $fillable = [
        'user_id',
        'title',
        'message',
        'type',
        'reference_id',
        'reference_type',
        'is_read',
        'read_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    //  Relationships 

    /** Notification owner. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Load the related Order / Payment / Reservation by hand (not morphTo). Null if missing. */
    public function reference(): ?Model
    {
        if (! $this->reference_type || ! $this->reference_id) {
            return null;
        }

        $modelClass = match ($this->reference_type) {
            'order'       => Order::class,
            'payment'     => Payment::class,
            'reservation' => Reservation::class,
            default       => null,
        };

        if (! $modelClass || ! class_exists($modelClass)) {
            return null;
        }

        return $modelClass::find($this->reference_id);
    }

    // Mutators 

    /** Mark as read (does nothing if already read). */
    public function markAsRead(): void
    {
        if (! $this->is_read) {
            $this->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
        }
    }

    // Scopes 

    /** Only unread notifications. */
    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    /** Only notifications of the given type. */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
}