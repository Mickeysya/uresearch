<?php

namespace App\Modules\Norhanis\Models;

use App\Modules\Core\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TravelDetail extends Model
{
    protected $fillable = [
        'application_id', 'type_of_request', 'other_request_specify',
        'travel_start_date', 'travel_end_date', 'duration_days',
        'reason_for_travel', 'destination_address', 'is_international',
        'contact_person_name', 'contact_person_no',
    ];

    protected function casts(): array
    {
        return [
            'travel_start_date' => 'date',
            'travel_end_date' => 'date',
            'is_international' => 'boolean',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** @return array<string, string> */
    public static function requestTypes(): array
    {
        return [
            'research_attachment' => 'Research Attachment',
            'training_seminar_talk' => 'Training / Seminar / Talk',
            'field_visit' => 'Field Visit',
            'meeting' => 'Meeting',
            'data_collection' => 'Data Collection',
            'joint_supervision' => 'Joint Supervision',
            'others' => 'Others',
        ];
    }
}
