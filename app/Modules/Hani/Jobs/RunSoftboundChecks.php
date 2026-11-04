<?php

namespace App\Modules\Hani\Jobs;

use App\Modules\Hani\Models\VivaDetail;
use App\Modules\Hani\Models\VivaSoftboundCheck;
use App\Modules\Hani\Models\VivaSoftboundDetail;
use App\Modules\Hani\Support\SoftboundChecks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;

/**
 * The slow half of the softbound checks: opening a thesis of up to 50 MB and
 * counting its words. Queued after the commit, so the student's submission
 * never waits on it and a failure here never undoes it. The Turnitin
 * report itself was read while the form was submitted (it decides the 25%
 * limit); the reading is on the check row already.
 */
class RunSoftboundChecks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 300;

    public int $tries = 2;

    public function __construct(public int $applicationId)
    {
        $this->afterCommit();
    }

    public function handle(SoftboundChecks $checks): void
    {
        $detail = VivaSoftboundDetail::with('application.documents')->where('application_id', $this->applicationId)->first();
        $row = VivaSoftboundCheck::firstOrNew(['application_id' => $this->applicationId]);

        if (! $detail) {
            return;
        }

        $document = fn (string $type) => $detail->application->documents->firstWhere('doc_type', $type);
        $bytes = function (string $type) use ($document) {
            $doc = $document($type);

            return $doc && Storage::disk('local')->exists($doc->path) ? Storage::disk('local')->get($doc->path) : null;
        };

        $notice = VivaDetail::where('application_id', $detail->viva_application_id)->first();

        $row->fill([
            'results' => $checks->run(
                $detail,
                $row->reading(),
                $bytes('Turnitin Report'),
                $bytes('Softbound Thesis'),
                $notice?->thesis_title,
                $notice?->programme_level,
            ),
            'engine' => app(\App\Modules\Hani\Support\Pdf\PdfReader::class)->engine(),
            'status' => VivaSoftboundCheck::DONE,
            'checked_at' => now(),
        ])->save();
    }

    public function failed(\Throwable $e): void
    {
        VivaSoftboundCheck::where('application_id', $this->applicationId)
            ->update(['status' => VivaSoftboundCheck::FAILED, 'checked_at' => now()]);
    }
}
