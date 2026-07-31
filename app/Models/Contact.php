<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contact extends Model
{
    use HasFactory;

    public const STATUS_UNREAD  = 'unread';
    public const STATUS_READ    = 'read';
    public const STATUS_REPLIED = 'replied';

    public const VALID_STATUSES = [
        self::STATUS_UNREAD,
        self::STATUS_READ,
        self::STATUS_REPLIED,
    ];

    protected $fillable = [
        'user_id',
        'name',
        'email',
        'phone',
        'subject',
        'message',
        'status',
        'replied_by',
        'reply_message',
        'replied_at',
    ];

    protected $casts = [
        'replied_at' => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────

    // Nullable — guests (not logged in) can also submit the contact form,
    // matching the migration's nullable()->nullOnDelete() on user_id.
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function repliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replied_by');
    }

    // ── Scopes ───────────────────────────────────────────────────────

    public function scopeUnread($query)
    {
        return $query->where('status', self::STATUS_UNREAD);
    }
}