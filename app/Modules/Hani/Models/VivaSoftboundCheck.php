<?php

namespace App\Modules\Hani\Models;

use App\Modules\Core\Models\Application;
use App\Modules\Hani\Support\Similarity\SimilarityReading;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The automatic checks on one softbound attempt. See Support\SoftboundChecks. */
class VivaSoftboundCheck extends Model
{
    public const QUEUED = 'queued';

    public const DONE = 'done';

    public const FAILED = 'failed';

    protected $fillable = ['application_id', 'status', 'similarity', 'results', 'engine', 'checked_at'];

    protected function casts(): array
    {
        return [
            'similarity' => 'array',
            'results' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function reading(): ?SimilarityReading
    {
        return SimilarityReading::fromArray($this->similarity);
    }

    /** Pass when nothing warned; Warning otherwise. Not-checked items do not count against it. */
    public function overall(): string
    {
        return collect($this->results ?? [])->contains('status', 'warning') ? 'warning' : 'pass';
    }
}
