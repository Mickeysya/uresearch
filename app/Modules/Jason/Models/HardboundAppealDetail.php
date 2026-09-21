<?php

namespace App\Modules\Jason\Models;

use App\Modules\Core\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HardboundAppealDetail extends Model
{
    protected $fillable = [
        'application_id', 'hardbound_application_id', 'justification', 'pfr_recommendation',
    ];

    /** The appeal itself. */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** The hardbound submission being appealed. */
    public function hardboundApplication(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'hardbound_application_id');
    }

    public function hardboundDetail(): ?HardboundSubmissionDetail
    {
        return HardboundSubmissionDetail::where('application_id', $this->hardbound_application_id)->first();
    }
}
