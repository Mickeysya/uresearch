<?php

namespace App\Modules\Hani\Jobs;

use App\Modules\Core\Models\Application;
use App\Modules\Hani\Models\VivaNoticeCheck;
use App\Modules\Hani\Support\NoticeChecks;
use App\Modules\Hani\Support\Pdf\PdfReader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The notice's document checks (Support\NoticeChecks), queued after the
 * commit so filing a notice never waits on reading up to five papers, and a
 * failure here never undoes it.
 */
class RunNoticeChecks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 300;

    public int $tries = 2;

    public function __construct(public int $applicationId)
    {
        $this->afterCommit();
    }

    public function handle(NoticeChecks $checks, PdfReader $reader): void
    {
        $application = Application::with(['documents', 'student'])->find($this->applicationId);

        if (! $application) {
            return;
        }

        $outcome = $checks->run($application);

        VivaNoticeCheck::updateOrCreate(['application_id' => $application->id], [
            'status' => VivaNoticeCheck::DONE,
            'results' => $outcome['results'],
            'papers' => $outcome['papers'],
            'engine' => $reader->engine(),
            'checked_at' => now(),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        VivaNoticeCheck::updateOrCreate(['application_id' => $this->applicationId], ['status' => VivaNoticeCheck::FAILED, 'checked_at' => now()]);
    }
}
