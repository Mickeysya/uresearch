<?php

namespace App\Modules\Jason\Models;

use App\Modules\Core\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentDetail extends Model
{
    protected $fillable = [
        'application_id', 'examiner_name', 'examiner_institution', 'examiner_email', 'examiner_expertise',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
