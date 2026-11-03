<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Nureen\Support\AttendanceSources\InboxFolderSource;
use App\Modules\Nureen\Support\AttendanceSources\PushEndpointSource;
use App\Modules\Nureen\Support\AttendanceSources\SimulatedSource;
use App\Modules\Nureen\Support\Contracts\AttendanceSource;

/**
 * Picks the AttendanceSource implementation from config, or an explicit
 * override for `attendance:sync --source=`. One place, so the container
 * binding in ModuleProvider and the command's flag can't drift into two
 * copies of the same match().
 */
class AttendanceSourceFactory
{
    public static function make(?string $key = null): AttendanceSource
    {
        return match ($key ?? config('nureen.attendance.source')) {
            'api' => new PushEndpointSource(),
            'simulated' => new SimulatedSource(),
            default => new InboxFolderSource(config('nureen.attendance.inbox_path')),
        };
    }
}
