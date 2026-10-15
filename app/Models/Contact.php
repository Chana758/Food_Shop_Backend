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

    // Relationships     

    /** Sender account (null for guests). */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Admin who replied. */
    public function repliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replied_by');
    }

    // Scopes

    /** Only unread messages. */
    public function scopeUnread($query)
    {
        return $query->where('status', self::STATUS_UNREAD);
    }
}