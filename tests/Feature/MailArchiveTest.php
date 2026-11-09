<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\SentMail;
use App\Modules\Chloe\Support\MailArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Tests\TestCase;

/** The permanent sent-mail archive: every message, in time order, append-only, and never the real folder from a test. */
class MailArchiveTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/mail-archive-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function on(): void
    {
        config(['mail_archive.enabled' => true, 'mail_archive.path' => $this->dir]);
    }

    public function test_it_is_off_by_default_under_the_test_environment_so_the_real_archive_is_never_written(): void
    {
        $this->assertFalse(MailArchive::enabled());
        $this->assertSame('array', config('mail.default'));

        Mail::raw('hello', fn ($m) => $m->to('a@example.test')->subject('Not archived'));

        $this->assertSame(0, SentMail::count());
        $this->assertDirectoryDoesNotExist($this->dir);
    }

    public function test_a_sent_message_is_stored_as_an_eml_file_and_one_row(): void
    {
        $this->on();

        Mail::raw('Body for Seat 31 in N2-02-03', fn ($m) => $m->to('a@example.test')->cc('b@example.test')->subject('Mail archive test'));

        $row = SentMail::firstOrFail();
        $this->assertSame('a@example.test', $row->to_addresses);
        $this->assertSame('b@example.test', $row->cc_addresses);
        $this->assertSame('Mail archive test', $row->subject);
        $this->assertSame('other', $row->module);
        $this->assertStringContainsString('room N2-02-03', $row->reference);
        $this->assertStringContainsString('seat 31', $row->reference);

        $file = $this->dir.'/'.$row->eml_path;
        $this->assertFileExists($file);
        $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/\d{8}-\d{6}-[a-z0-9]{8}\.eml$#', $row->eml_path);
        $this->assertStringContainsString('Subject: Mail archive test', file_get_contents($file));
        $this->assertSame(hash_file('sha256', $file), $row->sha256);
        $this->assertSame(filesize($file), (int) $row->size_bytes);
    }

    public function test_the_time_is_kuala_lumpur_wall_clock_time(): void
    {
        $this->on();
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-11-08 08:00:00', 'Asia/Kuala_Lumpur'));

        Mail::raw('x', fn ($m) => $m->to('a@example.test')->subject('Clock'));

        $this->assertSame('2026-11-08 08:00:00', SentMail::firstOrFail()->sent_at);
        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_module_comes_from_the_class(): void
    {
        $this->assertSame('candidacy', MailArchive::module(\App\Modules\Chloe\Notifications\CandidacyExpiryReminder::class));
        $this->assertSame('workstation', MailArchive::module(\App\Modules\Chloe\Notifications\WorkstationStatusNotice::class));
        $this->assertSame('workstation', MailArchive::module(\App\Modules\Chloe\Notifications\LockerKeyNotice::class));
        $this->assertSame('core', MailArchive::module(\App\Modules\Core\Notifications\ApplicationDecided::class));
        $this->assertSame('other', MailArchive::module(null));
    }

    public function test_rows_cannot_be_updated_or_deleted(): void
    {
        $this->on();
        Mail::raw('x', fn ($m) => $m->to('a@example.test')->subject('Keep me'));
        $row = SentMail::firstOrFail();

        try {
            $row->update(['subject' => 'changed']);
            $this->fail('update must be refused');
        } catch (LogicException) {
            $this->assertSame('Keep me', $row->fresh()->subject);
        }
        $this->expectException(LogicException::class);
        $row->delete();
    }

    public function test_a_failure_to_archive_never_stops_the_mail(): void
    {
        config(['mail_archive.enabled' => true, 'mail_archive.path' => '/proc/not-writable/archive']);

        Mail::raw('x', fn ($m) => $m->to('a@example.test')->subject('Still sent'));

        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
        $this->assertSame(0, SentMail::count());
    }

    public function test_the_list_command_prints_in_time_order_and_filters(): void
    {
        $this->on();
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-11-08 09:00:00', 'Asia/Kuala_Lumpur'));
        Mail::raw('x', fn ($m) => $m->to('late@example.test')->subject('Second'));
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-11-08 08:00:00', 'Asia/Kuala_Lumpur'));
        Mail::raw('x', fn ($m) => $m->to('early@example.test')->subject('First'));
        \Illuminate\Support\Carbon::setTestNow();

        $this->artisan('mail:archive-list', ['--date' => '2026-11-08'])
            ->expectsOutputToContain('2 e-mail(s)')
            ->assertSuccessful();
        $this->assertSame(['First', 'Second'], SentMail::orderBy('sent_at')->pluck('subject')->all());
        $this->artisan('mail:archive-list', ['--to' => 'early'])->expectsOutputToContain('1 e-mail(s)')->assertSuccessful();
        $this->artisan('mail:archive-list', ['--date' => '2026-11-09'])->expectsOutputToContain('No archived e-mail matches.')->assertSuccessful();
        $this->artisan('mail:archive-list', ['--date' => 'bad'])->assertFailed();
    }
}
