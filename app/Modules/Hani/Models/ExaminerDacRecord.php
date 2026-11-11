<?php

namespace App\Modules\Hani\Models;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One DAC decision on an examiner nomination. See ExaminerDacController. */
class ExaminerDacRecord extends Model
{
    public const ENDORSED = 'endorsed';

    public const NOT_ENDORSED = 'not_endorsed';

    protected $fillable = [
        'nomination_id', 'application_id', 'outcome', 'meeting_date', 'reference',
        'minutes_path', 'minutes_name', 'reason', 'recorded_by_id',
    ];

    protected function casts(): array
    {
        return ['meeting_date' => 'date'];
    }

    public function nomination(): BelongsTo
    {
        return $this->belongsTo(ExaminerNomination::class, 'nomination_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    /** The latest DAC endorsement of a nomination's application, or null. */
    public static function endorsementFor(int $applicationId): ?self
    {
        return self::where('application_id', $applicationId)->where('outcome', self::ENDORSED)->latest('id')->first();
    }

    /** "DAC endorsed on 12 Nov 2026 (ref DAC/CS/2026/11)" */
    public function label(): string
    {
        return 'DAC endorsed on '.$this->meeting_date?->format('j M Y').($this->reference ? " (ref {$this->reference})" : '');
    }
}
