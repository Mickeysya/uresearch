<?php

namespace App\Modules\Haziq\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Publication extends Model
{
    use HasFactory;

    protected $table = 'gra_publications';

    protected $fillable = [
        'profile_id', 'stage_gate_id', 'publication_type', 'authors', 'publication_year', 'title',
        'journal_or_conference', 'volume_chapter', 'page_number', 'doi', 'issn', 'database_quartile', 'impact_factor',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(GraGaStudentProfile::class, 'profile_id');
    }

    public function stageGate(): BelongsTo
    {
        return $this->belongsTo(StageGate::class);
    }
}
