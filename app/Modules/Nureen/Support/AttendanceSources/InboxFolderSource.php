<?php

namespace App\Modules\Nureen\Support\AttendanceSources;

use App\Modules\Nureen\Support\AttendanceSheet;
use App\Modules\Nureen\Support\Contracts\AttendanceSource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use SplFileInfo;

/**
 * Stands in for the nightly Power Automate export: files simply appear in a
 * folder. Every file present is read the same way the manual upload reads
 * one -- same header check via AttendanceSheet::readFile(), same row shape
 * -- then moved to /processed, or to /failed with a `.reason.txt` beside
 * it, so a file is never read twice and one bad file does not stop the
 * next sync run from finding the good ones.
 */
class InboxFolderSource implements AttendanceSource
{
    public function __construct(protected string $path) {}

    public function label(): string
    {
        return 'Inbox Folder';
    }

    public function pull(): Collection
    {
        $this->ensureDirectories();

        $rows = collect();

        foreach (File::files($this->path) as $file) {
            $parsed = AttendanceSheet::readFile($file->getPathname());

            if ($parsed['error']) {
                $this->fail($file, $parsed['error']);

                continue;
            }

            $rows = $rows->concat($parsed['rows']);

            $this->succeed($file);
        }

        return $rows;
    }

    protected function ensureDirectories(): void
    {
        foreach (['', '/processed', '/failed'] as $sub) {
            if (! File::isDirectory($this->path.$sub)) {
                File::makeDirectory($this->path.$sub, 0755, true);
            }
        }
    }

    protected function succeed(SplFileInfo $file): void
    {
        File::move($file->getPathname(), $this->path.'/processed/'.$file->getFilename());
    }

    protected function fail(SplFileInfo $file, string $reason): void
    {
        File::move($file->getPathname(), $this->path.'/failed/'.$file->getFilename());
        File::put($this->path.'/failed/'.$file->getFilename().'.reason.txt', $reason);
    }
}
