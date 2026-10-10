<?php

namespace App\Modules\Jason\Models;

use App\Modules\Core\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentExaminer extends Model
{
    public const TYPE_INTERNAL = 'internal';

    public const TYPE_EXTERNAL = 'external';

    protected $fillable = [
        'application_id', 'pool_examiner_id', 'examiner_type', 'examiner_role', 'examiner_name', 'examiner_institution',
        'examiner_address', 'examiner_email', 'examiner_expertise', 'letter_ref_no',
        'appointed_at', 'pack_sent_at',
    ];

    protected function casts(): array
    {
        return ['appointed_at' => 'datetime', 'pack_sent_at' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** The list entry this panel member was picked from, where there was one. */
    public function poolExaminer(): BelongsTo
    {
        return $this->belongsTo(PoolExaminer::class, 'pool_examiner_id');
    }

    public function packSent(): bool
    {
        return $this->pack_sent_at !== null;
    }

    public const ROLE_MAIN = 'main';

    public const ROLE_BACKUP = 'backup';

    public function isBackup(): bool
    {
        return $this->examiner_role === self::ROLE_BACKUP;
    }

    public function isInternal(): bool
    {
        return $this->examiner_type === self::TYPE_INTERNAL;
    }

    public function typeLabel(): string
    {
        return $this->isInternal() ? 'Internal Examiner' : 'External Examiner';
    }

    /** "Internal Examiner (backup)", for screens that list a whole panel. */
    public function panelLabel(): string
    {
        return $this->typeLabel().($this->examiner_role ? " ({$this->examiner_role})" : '');
    }

    /**
     * The CGS templates file the two letters under different series --
     * PGS for external appointments, CGS for internal ones.
     */
    public function refSeries(): string
    {
        return $this->isInternal() ? 'CGS' : 'PGS';
    }
}
