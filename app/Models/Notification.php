<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    use HasFactory;

    // Matches the migration comment: 'order', 'payment', 'reservation',
    // 'promo', etc. Kept as constants for the common cases this app
    // already generates notifications for; 'type' itself is just a plain
    // string column, so new types can be added without a migration.
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

    // ── Relationships ────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Manual "polymorphic-style" resolver — the migration uses plain
    // reference_id/reference_type columns rather than Laravel's native
    // morphTo() columns (which would be {column}_id/{column}_type), so
    // this helper resolves the related model by hand instead of using
    // morphTo(). Returns null if reference_type/reference_id is missing
    // or the target model class doesn't exist.
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

    // ── Mutators ─────────────────────────────────────────────────────

    public function markAsRead(): void
    {
        if (! $this->is_read) {
            $this->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
        }
    }

    // ── Scopes ───────────────────────────────────────────────────────

    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
}