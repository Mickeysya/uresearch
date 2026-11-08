<?php

namespace App\Modules\Chloe\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One archived e-mail (see Support\MailArchive). Append-only, like AssistantLog and WorkstationHistory: there is no
 * route or screen, and the model itself refuses to update or delete a row so a stray call cannot rewrite the record.
 */
class SentMail extends Model
{
    protected $table = 'chloe_sent_mails';

    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The sent-mail archive is append-only: rows cannot be updated.'));
        static::deleting(fn () => throw new LogicException('The sent-mail archive is append-only: rows cannot be deleted.'));
    }
}
