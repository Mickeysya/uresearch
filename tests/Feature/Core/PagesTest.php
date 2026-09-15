<?php

namespace Tests\Feature\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Documents and Calendar — the two shell pages that were placeholders.
 *
 * The one that matters is the visibility test: the Documents page lists rows
 * from a table every module writes into, so "a student sees only their own"
 * has to be asserted rather than assumed.
 */
class PagesTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    public function test_documents_lists_only_what_the_viewer_may_download(): void
    {
        Storage::fake('local');

        $mine = $this->student('22001001', 'a@test.my');
        $theirs = $this->student('22001002', 'b@test.my');
        $supervisor = $this->supervisor();
        $mine->update(['supervisor_id' => $supervisor->id]);
        $theirs->update(['supervisor_id' => $supervisor->id]);

        foreach ([[$mine, 'mine.pdf'], [$theirs, 'theirs.pdf']] as [$student, $file]) {
            $this->actingAs($student)->post(route('travel.store'), [
                'type_of_request' => 'research_attachment',
                'travel_start_date' => now()->addDays(7)->toDateString(),
                'travel_end_date' => now()->addDays(9)->toDateString(),
                'reason_for_travel' => 'Workshop',
                'destination_address' => 'Kuala Lumpur',
                'supporting_document' => UploadedFile::fake()->create($file, 40, 'application/pdf'),
            ])->assertRedirect();
        }

        $this->actingAs($mine)
            ->get(route('documents.index'))
            ->assertOk()
            ->assertSee('mine.pdf')
            ->assertDontSee('theirs.pdf');
    }

    public function test_the_calendar_renders_and_shows_a_real_deadline(): void
    {
        $student = $this->student();
        $student->update(['supervisor_id' => $this->supervisor()->id]);

        \App\Modules\Norhanis\Models\Candidacy::create([
            'student_id' => $student->id,
            'programme_type' => 'phd_ft',
            'candidature_start_date' => now()->subMonths(6),
            'rpd_deadline' => now()->addDays(10),
            'status' => 'active',
        ]);

        $this->actingAs($student)
            ->get(route('calendar.index'))
            ->assertOk()
            ->assertSee('RPD deadline');
    }

    public function test_the_calendar_survives_a_junk_month_parameter(): void
    {
        $this->actingAs($this->student())
            ->get(route('calendar.index', ['month' => 'not-a-month']))
            ->assertOk();
    }

    public function test_every_role_can_open_both_pages(): void
    {
        foreach ([$this->student(), $this->supervisor(), $this->cgs(), $this->dean()] as $user) {
            $this->actingAs($user)->get(route('documents.index'))->assertOk();
            $this->actingAs($user)->get(route('calendar.index'))->assertOk();
        }
    }
}
