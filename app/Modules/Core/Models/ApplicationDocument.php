<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Every upload, for every module, lands here.
 *
 * The legacy app declared this table and then never used it -- GA Extension
 * stashed a path on its own detail row and wrote the file under the webroot
 * with its original name and no validation, which made a .php upload remote
 * code execution. Files now go to the private disk under storage/app and are
 * only ever served back through a controller.
 */
class ApplicationDocument extends Model
{
    protected $fillable = [
        'application_id', 'doc_type', 'original_name', 'path', 'mime_type', 'size_bytes',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function url(): string
    {
        return route('documents.show', $this);
    }

    public function exists(): bool
    {
        return Storage::disk('local')->exists($this->path);
    }
}
