<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only record that a given nurturing/transactional email was sent to a
 * waitlist entry. The unique (waitlist_entry_id, email_key) index is the drip's
 * idempotency guard — see WaitlistNurtureService. Rows are never updated, only
 * inserted, so there is no updated_at; sent_at is set on insert.
 */
class WaitlistEmailLog extends Model
{
    protected $table = 'waitlist_email_log';

    public $timestamps = false;

    protected $fillable = ['waitlist_entry_id', 'email_key', 'sent_at'];

    protected $casts = ['sent_at' => 'datetime'];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(WaitlistEntry::class, 'waitlist_entry_id');
    }
}
