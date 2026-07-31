<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reservation extends Model
{
    use HasFactory;

    // Mirrors the enum('status', [...]) defined in the migration —
    // kept as constants so controllers/validation never hardcode raw
    // strings that could drift out of sync with the schema.
    public const STATUS_PENDING   = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_REJECTED  = 'rejected';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const VALID_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONFIRMED,
        self::STATUS_REJECTED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'user_id',
        'table_id',
        'guest_count',
        'reserved_at',
        'status',
        'notes',
        'confirmed_by',
        'confirmed_at',
    ];

    protected $casts = [
        'reserved_at'  => 'datetime',
        'confirmed_at' => 'datetime',
        'guest_count'  => 'integer',
    ];

    // ── Relationships ────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    // The admin/staff member who confirmed or rejected this reservation.
    // Nullable because it's only set once a decision has been made.
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    // ── Scopes ───────────────────────────────────────────────────────

    public function scopeUpcoming($query)
    {
        return $query->where('reserved_at', '>=', now())
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_CONFIRMED]);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}