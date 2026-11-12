<?php

namespace Tests\Feature\Hani\Concerns;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Storage::fake('local'), but in a folder no other test process shares.
 *
 * Storage::fake() always uses storage/framework/testing/disks/local and
 * empties it in every test's setUp. Two `php artisan test` runs at once
 * (yours and a teammate's, or a terminal and an assistant) therefore wipe
 * each other's files mid-test. The demo seeder writes a file (the Dean's
 * demo signature) and reads it back minutes later, so HaniDemoSeederTest
 * failed whenever another run overlapped it: "Upload your Re-viva
 * Correction Letters signature on My Signatures before approving."
 * (12 Nov 2026, twice.) One folder per process, removed afterwards.
 */
trait FakesAPrivateLocalDisk
{
    protected ?string $privateDiskRoot = null;

    protected function fakePrivateLocalDisk(): void
    {
        $this->privateDiskRoot = storage_path('framework/testing/disks/local_'.getmypid().'_'.bin2hex(random_bytes(4)));
        (new Filesystem)->ensureDirectoryExists($this->privateDiskRoot);

        // Not Storage::fake(): that would empty the shared folder under
        // whichever other run is using it.
        Storage::set('local', Storage::createLocalDriver(['root' => $this->privateDiskRoot] + config('filesystems.disks.local')));

        $this->beforeApplicationDestroyed(function () {
            if ($this->privateDiskRoot) {
                (new Filesystem)->deleteDirectory($this->privateDiskRoot);
            }
        });
    }
}
