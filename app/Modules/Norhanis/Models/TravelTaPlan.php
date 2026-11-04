<?php

namespace App\Modules\Norhanis\Models;

use App\Modules\Core\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TravelTaPlan extends Model
{
    protected $fillable = [
        'application_id', 'total_hours_required', 'hours_completed',
        'hours_during_travel', 'management_method',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function dates(): HasMany
    {
        return $this->hasMany(TravelTaPlanDate::class, 'ta_plan_id')->orderBy('date');
    }

    /** @return array<string, string> */
    public static function managementMethods(): array
    {
        return [
            'completed_before_travel' => 'Completed before travel',
            'rescheduled_after_travel' => 'Rescheduled after travel',
            'no_ta_activities_affected' => 'No TA activities affected',
            'replacement_arrangement' => 'Replacement arrangement',
        ];
    }

    /** Methods that need at least one proposed date. */
    public static function methodsNeedingDates(): array
    {
        return ['rescheduled_after_travel', 'replacement_arrangement'];
    }
}
