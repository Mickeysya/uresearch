<?php

namespace App\Modules\Chloe\Support;

use App\Modules\Chloe\Models\SentMail;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;

/**
 * Keeps a permanent copy of every message the mailer hands to its transport (queued or direct, notification, mailable
 * or raw), independent of Mailpit. The raw message goes to an .eml file under storage/app/private/mail-archive/YYYY/MM/
 * (never public) and one row to chloe_sent_mails.
 *
 * Automated tests never write to the real archive: unless a test switches it on explicitly (config mail_archive.enabled
 * and a temporary mail_archive.path), the archive is off whenever the app runs in the "testing" environment.
 * Nothing here changes a message, its recipients or its timing.
 */
class MailArchive
{
    public const TIMEZONE = 'Asia/Kuala_Lumpur';

    public static function enabled(): bool
    {
        return (bool) config('mail_archive.enabled', ! app()->environment('testing'));
    }

    public static function root(): string
    {
        return rtrim((string) (config('mail_archive.path') ?: storage_path('app/private/mail-archive')), '/');
    }

    public static function record(MessageSent $event): SentMail
    {
        $raw = $event->sent->toString();
        $message = $event->message;
        $now = Carbon::now(self::TIMEZONE);

        $dir = self::root().'/'.$now->format('Y/m');
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Cannot create the mail archive folder.');
        }
        // The web server, the queue worker, the scheduler and an artisan run in a shell are different users in the
        // containers; whoever writes first must not lock the others out. The folder is under storage/app/private
        // (never served), on the developer's own machine.
        foreach ([self::root(), dirname($dir), $dir] as $folder) {
            @chmod($folder, 0777);
        }
        $relative = $now->format('Y/m').'/'.$now->format('Ymd-His').'-'.Str::lower(Str::random(8)).'.eml';
        if (file_put_contents(self::root().'/'.$relative, $raw, LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write the mail archive file.');
        }
        @chmod(self::root().'/'.$relative, 0666);

        $class = $event->data['__laravel_notification'] ?? $event->data['__laravel_mailable'] ?? null;
        $subject = (string) $message->getSubject();
        $text = (string) ($message->getTextBody() ?? '');

        return SentMail::create([
            'sent_at' => $now->format('Y-m-d H:i:s'),
            'to_addresses' => self::addresses($message->getTo()) ?: '(none)',
            'cc_addresses' => self::addresses($message->getCc()) ?: null,
            'subject' => Str::limit($subject, 480, ''),
            'mail_class' => $class,
            'module' => self::module($class),
            'reference' => self::reference($subject, $text),
            'message_id' => $event->sent->getMessageId(),
            'eml_path' => $relative,
            'size_bytes' => strlen($raw),
            'sha256' => hash('sha256', $raw),
        ]);
    }

    /** @param  Address[]  $list */
    private static function addresses(array $list): string
    {
        return implode(', ', array_map(fn (Address $a) => $a->getAddress(), $list));
    }

    public static function module(?string $class): string
    {
        if (! $class || ! preg_match('/^App\\\\Modules\\\\([^\\\\]+)\\\\/', $class, $m)) {
            return 'other';
        }
        if ($m[1] === 'Chloe') {
            if (preg_match('/Candidacy/', $class)) {
                return 'candidacy';
            }
            if (preg_match('/Workstation|LockerKey|Seat/', $class)) {
                return 'workstation';
            }

            return 'chloe';
        }

        return Str::lower($m[1]);
    }

    /** Appeal number (subjects start with it), room / seat code, reminder months -- whatever the message itself states. */
    public static function reference(string $subject, string $text): ?string
    {
        $parts = [];
        if (preg_match('/^([A-Z0-9][A-Za-z0-9\/\-]*\d[A-Za-z0-9\/\-]*)\s/', $subject, $m)) {
            $parts[] = 'appeal '.$m[1];
        }
        if (preg_match('/\b([A-Z]\d-\d{2}-\d{2})\b/', $subject.' '.$text, $m)) {
            $parts[] = 'room '.$m[1];
        }
        if (preg_match('/\bSeat\s+([A-Z0-9][A-Za-z0-9\-]*)/i', $subject.' '.$text, $m) && preg_match('/\d/', $m[1])) {
            $parts[] = 'seat '.$m[1];
        }
        if (preg_match('/\b(\d+)\s+months?\b/i', $subject, $m)) {
            $parts[] = 'reminder '.$m[1].' month(s)';
        }

        return $parts ? Str::limit(implode('; ', $parts), 250, '') : null;
    }
}
