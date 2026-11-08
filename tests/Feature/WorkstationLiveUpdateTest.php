<?php

namespace Tests\Feature;

use App\Modules\Chloe\Assistant\FakeModel;
use App\Modules\Chloe\Assistant\Pipeline;
use App\Modules\Chloe\Exports\WorkstationSeatsExport;
use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Models\StudentGender;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationHistory;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Chloe\Services\WorkstationAllocator;
use App\Modules\Chloe\Support\WorkstationLiveVersion;
use App\Modules\Chloe\Support\WorkstationStatistics as Stats;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Support\MakesEligibleWorkstationStudents;
use Tests\Support\MakesFakeAssistant;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Live data on the CGS Workstation pages (see WorkstationLiveVersion and
 * public/js/chloe-workstation-live.js).
 *
 * The incident behind it: after a key was marked Collected, the "Key
 * Pending Collection" number stayed stale. Two causes -- the count itself
 * disagreed with the seat list it links to (a stale key row on a released
 * request was counted), and the pages could be re-shown from the browser's
 * history copy because they were `no-cache`, not `no-store`.
 *
 * Every test here runs with NO cache clearing between an action and the
 * check: nothing in the numbers is cached, so an action shows up at once.
 */
class WorkstationLiveUpdateTest extends TestCase
{
    use MakesEligibleWorkstationStudents;
    use MakesFakeAssistant;
    use MakesUsers;
    use RefreshDatabase;

    protected User $cgs;

    protected WorkstationLocation $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFakeAssistant();
        Notification::fake();
        $this->cgs = $this->cgs();
        $this->room = WorkstationLocation::create(['block' => 'N2', 'room_code' => 'N2-LV-01', 'gender' => 'male', 'name' => 'N2-LV-01']);
    }

    // =====================================================================
    // Fixture helpers
    // =====================================================================

    protected function seat(string $code, string $status = Workstation::STATUS_AVAILABLE): Workstation
    {
        return Workstation::create(['workstation_location_id' => $this->room->id, 'seat_code' => $code, 'position' => (int) $code, 'status' => $status]);
    }

    protected function studentWithSeat(string $n, ?string $keyStatus = null, ?string $seatStatus = null): array
    {
        $student = $this->eligibleStudent('2200'.str_pad($n, 4, '0', STR_PAD_LEFT), "live{$n}@test.my", ['name' => "Live Student {$n}"]);
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $seat = $this->seat($n, $seatStatus ?? Workstation::STATUS_OCCUPIED);
        $request = WorkstationRequest::create([
            'workstation_id' => $seat->id, 'student_id' => $student->id,
            'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now()->subDays(3),
        ]);
        $key = $keyStatus ? $this->key($request, $student, $keyStatus) : null;

        return [$student, $request, $seat, $key];
    }

    protected function key(WorkstationRequest $request, ?User $student, string $status, array $extra = []): LockerKey
    {
        return LockerKey::create(array_merge([
            'workstation_request_id' => $request->id,
            'student_id' => $student?->id,
            'status' => $status,
            'requested_at' => now()->subDays(2),
            'collected_at' => $status === LockerKey::STATUS_REQUESTED ? null : now()->subDay(),
        ], $extra));
    }

    /**
     * A catalogue where every stats-bar number is non-zero:
     *   10 occupied/no key   11 occupied/pending key   12 occupied/collected key
     *   20,21 available      30 maintenance
     *   40 awaiting key return with an OVERDUE Return Pending key
     *   41 awaiting key return with a not-yet-due Return Pending key
     *
     * @return array<string, mixed>
     */
    protected function world(): array
    {
        $w = [];
        [$w['a'], $w['reqA'], $w['seatA']] = $this->studentWithSeat('10');
        [$w['b'], $w['reqB'], $w['seatB'], $w['keyB']] = $this->studentWithSeat('11', LockerKey::STATUS_REQUESTED);
        [$w['c'], $w['reqC'], $w['seatC'], $w['keyC']] = $this->studentWithSeat('12', LockerKey::STATUS_COLLECTED);
        $w['avail1'] = $this->seat('20');
        $w['avail2'] = $this->seat('21');
        $w['maint'] = $this->seat('30', Workstation::STATUS_DISABLED);

        foreach (['40' => now()->subDays(2), '41' => now()->addDays(5)] as $code => $due) {
            [$former, $request, $seat] = $this->studentWithSeat($code, null, Workstation::STATUS_AWAITING_KEY_RETURN);
            $request->update(['status' => WorkstationRequest::STATUS_RELEASED, 'released_at' => now()->subDay()]);
            $w['awaiting'.$code] = $seat;
            $w['awaitingKey'.$code] = $this->key($request, $former, LockerKey::STATUS_RETURN_PENDING, ['return_due_at' => $due]);
        }

        return $w;
    }

    protected function allocator(): WorkstationAllocator
    {
        return app(WorkstationAllocator::class);
    }

    protected function snapshot(): array
    {
        return ['version' => WorkstationLiveVersion::cgs(), 'stats' => Stats::overall()];
    }

    /**
     * Runs $action and asserts, WITHOUT clearing any cache: the live version
     * changed, and every stats-bar number moved by exactly the expected amount
     * (anything not listed must not move).
     *
     * @param  array<string, int>  $expected  stat key => delta
     */
    protected function step(string $label, callable $action, array $expected): void
    {
        $before = $this->snapshot();
        $action();
        $after = $this->snapshot();

        $this->assertNotSame($before['version'], $after['version'], "[{$label}] the live version did not change");

        foreach ($before['stats'] as $key => $was) {
            $this->assertSame(
                $was + ($expected[$key] ?? 0),
                $after['stats'][$key],
                "[{$label}] '{$key}' should have moved by ".($expected[$key] ?? 0).", it went {$was} -> {$after['stats'][$key]}"
            );
        }

        $this->assertEveryNumberAgrees($label);
    }

    // =====================================================================
    // The cross-checks run after every step
    // =====================================================================

    protected function assertEveryNumberAgrees(string $label): void
    {
        $bar = Stats::overall();

        // 1. Each stats-bar number equals the size of the list its card links to.
        $this->assertEveryCardEqualsItsList($label);

        // 2. The live endpoint returns the same numbers, computed fresh.
        $live = $this->actingAs($this->cgs)->getJson(route('workstation.cgs.live.stats'))->assertOk()->json();
        $this->assertSame($bar, $live['stats'], "[{$label}] live stats endpoint differs from the stats bar");
        $this->assertSame(WorkstationLiveVersion::cgs(), $live['version']);
        $this->assertSame(Stats::roomCards(), $live['rooms']);

        // 3. Insights: the same seats, classified the same way.
        $rows = collect(Stats::insightsFactRows());
        $pending = $rows->where('status', 'available')->count() - $bar['available'];
        $this->assertSame($bar['total'], $rows->count(), "[{$label}] Insights seat count");
        $this->assertSame($bar['occupied'], $rows->where('status', 'occupied')->count(), "[{$label}] Insights occupied");
        $this->assertSame($bar['maintenance'], $rows->where('status', 'maintenance')->count(), "[{$label}] Insights maintenance");
        $this->assertSame($bar['awaiting_key_return'], $rows->where('status', 'awaiting_key_return')->count(), "[{$label}] Insights awaiting key return");
        $this->assertSame($bar['key_pending_collection'], $rows->where('key', 'pending_collection')->count(), "[{$label}] Insights key pending collection");
        // Documented difference: Insights folds a seat mid-selection (Pending/Reserved) into
        // 'available' (WorkstationStatistics::insightsFactRow()); the bar's Available does not.
        $this->assertSame(
            Workstation::whereIn('status', [Workstation::STATUS_PENDING, Workstation::STATUS_RESERVED])->count(),
            $pending,
            "[{$label}] Insights Available differs from the bar by more than the seats mid-selection"
        );

        // 4. The live Insights endpoint serves those same rows.
        $liveInsights = $this->actingAs($this->cgs)->getJson(route('workstation.cgs.live.insights'))->assertOk()->json();
        $this->assertCount($bar['total'], $liveInsights['rows']);
        $this->assertSame($bar['key_pending_collection'], collect($liveInsights['rows'])->where('key', 'pending_collection')->count());

        // 5. The assistant answers from the same numbers (keyword rule path, no model).
        FakeModel::reset();
        FakeModel::forceUnavailable();
        $pipeline = app(Pipeline::class);
        $answer = $pipeline->ask($this->cgs, 'how many keys are pending collection', [])['answer'];
        $this->assertStringStartsWith("{$bar['key_pending_collection']} locker key(s) pending collection", $answer, "[{$label}] assistant pending collection");
        $answer = $pipeline->ask($this->cgs, 'how many seats are available', [])['answer'];
        $this->assertStringContainsString("{$bar['available']} Available, {$bar['occupied']} Occupied", $answer, "[{$label}] assistant available");

        // 6. The exports hold exactly the seats those numbers count.
        foreach (['key_status' => ['requested', $bar['key_pending_collection']], 'seat_status' => ['available', $bar['available']]] as $param => [$value, $count]) {
            Excel::fake();
            $this->actingAs($this->cgs)->get(route('workstation.cgs.export.seats', [$param => $value]))->assertOk();
            Excel::assertDownloaded(
                'workstation_seats_'.now('Asia/Kuala_Lumpur')->format('Y-m-d').'.xlsx',
                fn (WorkstationSeatsExport $export) => $export->collection()->count() === $count
            );
        }
    }

    protected function assertEveryCardEqualsItsList(string $label): void
    {
        $html = $this->actingAs($this->cgs)->get(route('workstation.cgs.operations'))->assertOk()->getContent();

        preg_match_all('/<a href="([^"]+)"[^>]*>\s*<div class="stat-card[^"]*"><div class="stat-number" data-live-stat="([a-z_]+)">(\d+)<\/div>/', $html, $cards, PREG_SET_ORDER);
        $this->assertCount(7, $cards, "[{$label}] the stats bar should have 7 cards");

        foreach ($cards as [, $href, $key, $number]) {
            if ($key === 'total') {
                $this->assertSame(Workstation::count(), (int) $number, "[{$label}] total");

                continue;
            }

            $list = $this->actingAs($this->cgs)->get(html_entity_decode($href))->assertOk()->getContent();
            preg_match('/(\d+) matching seats?/', $list, $m);
            $this->assertSame((int) $number, (int) ($m[1] ?? -1), "[{$label}] the '{$key}' card says {$number} but the list it links to has ".($m[1] ?? 'no count'));
        }
    }

    // =====================================================================
    // Part 2: the definitions
    // =====================================================================

    public function test_each_stats_bar_number_equals_the_count_of_the_list_it_links_to(): void
    {
        $this->world();

        $this->assertEveryCardEqualsItsList('baseline');

        $bar = Stats::overall();
        $this->assertSame(1, $bar['key_pending_collection']);
        $this->assertSame(2, $bar['awaiting_key_return']);
        $this->assertSame(1, $bar['key_return_overdue']);
        $this->assertSame(1, $bar['maintenance']);
    }

    /** The incident: a Pending Collection key left on a request that was already released was counted by the bar but not by the list. */
    public function test_a_stale_pending_key_on_a_released_request_is_not_counted(): void
    {
        $w = $this->world();
        [$gone, $releasedRequest] = $this->studentWithSeat('50', null, Workstation::STATUS_AVAILABLE);
        $releasedRequest->update(['status' => WorkstationRequest::STATUS_RELEASED, 'released_at' => now()->subDays(5)]);
        $this->key($releasedRequest, $gone, LockerKey::STATUS_REQUESTED);

        $this->assertSame(2, LockerKey::where('status', LockerKey::STATUS_REQUESTED)->count(), 'two raw pending rows exist');
        $this->assertSame(1, Stats::keysPendingCollection(), 'only the seat whose current key is pending counts');
        $this->assertEveryNumberAgrees('stale key');
    }

    public function test_only_the_latest_key_of_a_request_counts(): void
    {
        $w = $this->world();
        // The pending key on seat 11 is superseded by a newer Collected one.
        $this->key($w['reqB'], $w['b'], LockerKey::STATUS_COLLECTED, ['requested_at' => now()]);

        $this->assertSame(0, Stats::keysPendingCollection());
        $this->assertEveryNumberAgrees('superseded key');
    }

    public function test_a_demo_profile_seat_counts_like_a_real_one(): void
    {
        $this->world();
        $profile = \App\Modules\Chloe\Models\DemoStudentProfile::create([
            'matric_no' => '22019999', 'name' => 'Demo Person', 'email' => 'demo9@utp.edu.my', 'gender' => 'male',
            'programme' => 'MSc', 'status' => 'active', 'candidacy_expiry_date' => now()->addYear(),
        ]);
        $seat = $this->seat('60', Workstation::STATUS_OCCUPIED);
        $request = WorkstationRequest::create(['workstation_id' => $seat->id, 'demo_profile_id' => $profile->id, 'status' => 'confirmed', 'requested_at' => now()]);
        LockerKey::create(['workstation_request_id' => $request->id, 'demo_profile_id' => $profile->id, 'status' => 'requested', 'requested_at' => now()]);

        $this->assertSame(2, Stats::keysPendingCollection());
        $this->assertEveryNumberAgrees('demo profile');
    }

    public function test_block_page_cards_and_the_live_rooms_come_from_the_same_counts(): void
    {
        $this->world();

        $html = $this->actingAs($this->cgs)->get(route('workstation.cgs.block', 'N2'))->assertOk()->getContent();

        foreach (Stats::roomCards() as $card) {
            $this->assertStringContainsString('data-live-room="'.$card['id'].'"', $html);
            $this->assertStringContainsString('Available: <span data-live-count="available">'.$card['available'].'</span>', $html);
            $this->assertStringContainsString('Occupied: <span data-live-count="occupied">'.$card['occupied'].'</span>', $html);
            $this->assertStringContainsString('Maintenance: <span data-live-count="maintenance">'.$card['maintenance'].'</span>', $html);
        }
    }

    // =====================================================================
    // Part 5.1(a): every action changes the version and the right numbers
    // =====================================================================

    public function test_mark_as_collected(): void
    {
        $w = $this->world();
        $this->step('mark collected', fn () => $w['keyB']->collect(WorkstationHistory::ACTOR_CGS, 'CGS'), ['key_pending_collection' => -1]);
    }

    public function test_mark_as_collected_through_the_cgs_seat_page(): void
    {
        $w = $this->world();
        $this->step('mark collected (UI route)', fn () => $this->actingAs($this->cgs)->post(route('workstation.cgs.seat.key-collected', $w['seatB']))->assertRedirect(), ['key_pending_collection' => -1]);
    }

    public function test_mark_as_returned(): void
    {
        $w = $this->world();
        $this->step('mark returned', fn () => $w['awaitingKey40']->markReturned(WorkstationHistory::ACTOR_CGS, 'CGS'), ['awaiting_key_return' => -1, 'available' => 1, 'key_return_overdue' => -1]);
    }

    public function test_key_request_and_key_cancel(): void
    {
        $w = $this->world();
        $this->step('key request', fn () => $this->actingAs($w['a'])->post(route('workstation.locker-key.request'))->assertRedirect(), ['key_pending_collection' => 1]);
        $this->step('key cancel', fn () => $this->actingAs($w['a'])->post(route('workstation.locker-key.cancel'))->assertRedirect(), ['key_pending_collection' => -1]);
    }

    public function test_seat_select_and_confirm(): void
    {
        $w = $this->world();
        $student = $this->eligibleStudent('22009001', 'newbie@test.my', ['name' => 'New Student']);
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);

        $this->step('seat select and confirm', function () use ($student, $w) {
            $this->assertTrue($this->allocator()->selectSeat($student, $w['avail1'])['selected']);
        }, ['available' => -1, 'occupied' => 1]);
    }

    public function test_student_return_workstation(): void
    {
        $w = $this->world();
        $this->step('student return', fn () => $this->actingAs($w['a'])->post(route('workstation.return'), ['reason' => 'No longer need a workstation'])->assertRedirect(), ['occupied' => -1, 'available' => 1]);
    }

    public function test_release(): void
    {
        $w = $this->world();
        // Seat 12 holds a Collected key: releasing it leaves the seat Awaiting Key Return.
        $this->step('release', fn () => $this->allocator()->releaseSeat($w['reqC'], WorkstationHistory::ACTOR_CGS, $this->cgs, 'System error'), ['occupied' => -1, 'awaiting_key_return' => 1]);
    }

    public function test_releasing_a_seat_with_a_pending_key_cancels_the_key(): void
    {
        $w = $this->world();
        $this->step('release with pending key', fn () => $this->allocator()->releaseSeat($w['reqB'], WorkstationHistory::ACTOR_CGS, $this->cgs, 'System error'), ['occupied' => -1, 'available' => 1, 'key_pending_collection' => -1]);
    }

    public function test_reassign(): void
    {
        $w = $this->world();
        // Seat 10 -> seat 20: one seat freed, one taken, so every count stays put -- but the version moves.
        $this->step('reassign', fn () => $this->allocator()->reassignSeat($w['reqA'], $w['avail1'], $this->cgs, 'Allocation error'), []);
        $this->assertSame(Workstation::STATUS_OCCUPIED, $w['avail1']->fresh()->status);
        $this->assertSame(Workstation::STATUS_AVAILABLE, $w['seatA']->fresh()->status);
    }

    public function test_assign(): void
    {
        $w = $this->world();
        $homeless = $this->eligibleStudent('22009002', 'homeless@test.my', ['name' => 'No Seat']);
        StudentGender::create(['student_id' => $homeless->id, 'gender' => 'male']);

        $this->step('assign', fn () => $this->allocator()->assignStudent($homeless, $w['avail1'], $this->cgs, 'System error'), ['available' => -1, 'occupied' => 1]);
    }

    public function test_set_maintenance_and_restore(): void
    {
        $w = $this->world();
        $this->step('set maintenance', fn () => $this->allocator()->setMaintenance($w['avail1'], $this->cgs, 'Repair needed'), ['available' => -1, 'maintenance' => 1]);
        $this->step('restore', fn () => $this->allocator()->restoreSeat($w['avail1']->fresh(), $this->cgs), ['available' => 1, 'maintenance' => -1]);
    }

    public function test_release_hold(): void
    {
        $w = $this->world();
        $this->step('release hold', fn () => $this->allocator()->releaseHold($w['awaiting40'], $this->cgs, 'Key lost'), ['awaiting_key_return' => -1, 'available' => 1, 'key_return_overdue' => -1]);
    }

    public function test_start_and_withdraw_a_termination(): void
    {
        $w = $this->world();
        $notice = null;
        // The seat stays Occupied during a notice, so no bar number moves -- the version still must.
        $this->step('start termination', function () use ($w, &$notice) {
            $notice = $this->allocator()->startTermination($w['reqA'], WorkstationHistory::ACTOR_CGS, $this->cgs, WorkstationAllocator::REASON_TERMINATION_DEFERMENT);
        }, []);
        $this->step('withdraw termination', fn () => $this->allocator()->withdrawTermination($notice, $this->cgs, 'Issued by mistake'), []);
    }

    public function test_the_automatic_key_cancellation(): void
    {
        $w = $this->world();
        $this->key($w['reqA'], $w['a'], LockerKey::STATUS_REQUESTED, ['requested_at' => now()->subDays(LockerKey::COLLECTION_PERIOD_DAYS + 1), 'collected_at' => null]);

        $this->assertSame(2, Stats::keysPendingCollection());
        $this->step('auto cancel', fn () => LockerKey::where('student_id', $w['a']->id)->where('status', LockerKey::STATUS_REQUESTED)->first()->processIfOverdue(), ['key_pending_collection' => -1]);
    }

    public function test_the_daily_job_changes_the_version(): void
    {
        $w = $this->world();
        $this->key($w['reqA'], $w['a'], LockerKey::STATUS_REQUESTED, ['requested_at' => now()->subDays(LockerKey::COLLECTION_PERIOD_DAYS + 1), 'collected_at' => null]);

        $this->step('remind-locker-keys job', fn () => $this->artisan('workstation:remind-locker-keys')->assertSuccessful(), ['key_pending_collection' => -1]);
    }

    public function test_demo_seeding_changes_the_version(): void
    {
        $this->world();
        foreach (range(1, 4) as $i) {
            $this->seat((string) (200 + $i), Workstation::STATUS_OCCUPIED); // occupied but untracked: the demo seed gives them a demo student
        }

        $before = WorkstationLiveVersion::cgs();
        $this->artisan('workstation:demo-seed')->assertSuccessful();

        $this->assertNotSame($before, WorkstationLiveVersion::cgs());
        $this->assertEveryNumberAgrees('demo seed');
    }

    public function test_nothing_changes_the_version_when_nothing_happens(): void
    {
        $this->world();

        $this->assertSame(WorkstationLiveVersion::cgs(), WorkstationLiveVersion::cgs());
        $this->actingAs($this->cgs)->get(route('workstation.cgs.operations'))->assertOk();
        $this->actingAs($this->cgs)->getJson(route('workstation.cgs.live.stats'))->assertOk();

        $first = $this->actingAs($this->cgs)->getJson(route('workstation.cgs.live.version'))->json('version');
        $second = $this->actingAs($this->cgs)->getJson(route('workstation.cgs.live.version'))->json('version');
        $this->assertSame($first, $second, 'polling must not itself change the version');
    }

    public function test_no_cache_entry_is_involved_in_any_number(): void
    {
        $w = $this->world();
        Cache::flush();
        $before = Stats::overall();
        // A write that bypasses every cache layer a request could have populated.
        $w['keyB']->collect(WorkstationHistory::ACTOR_CGS, 'CGS');
        $this->assertSame($before['key_pending_collection'] - 1, Stats::overall()['key_pending_collection']);
        $this->assertSame([], array_filter(
            ['remember' => (bool) preg_match('/Cache::remember|->remember\(|static \$/', file_get_contents(app_path('Modules/Chloe/Support/WorkstationStatistics.php')))]
        ), 'WorkstationStatistics must not cache or memoise anything');
    }

    // =====================================================================
    // Student version: own data only
    // =====================================================================

    public function test_the_student_version_changes_for_their_own_actions_only(): void
    {
        $w = $this->world();

        $mine = fn () => $this->actingAs($w['a'])->getJson(route('workstation.live.student-version'))->assertOk()->json('version');

        $v0 = $mine();
        $this->assertSame($v0, $mine(), 'polling alone never changes it');

        // Another student's key being collected must not move MY version...
        $w['keyB']->collect(WorkstationHistory::ACTOR_CGS, 'CGS');
        $this->assertSame($v0, $mine(), "someone else's action leaked into my version");

        // ...but my own key request, and CGS acting on my key, must.
        $this->actingAs($w['a'])->post(route('workstation.locker-key.request'))->assertRedirect();
        $v1 = $mine();
        $this->assertNotSame($v0, $v1);

        LockerKey::where('student_id', $w['a']->id)->first()->collect(WorkstationHistory::ACTOR_CGS, 'CGS');
        $this->assertNotSame($v1, $mine());
    }

    public function test_a_termination_notice_moves_the_students_version(): void
    {
        $w = $this->world();
        $v0 = $this->actingAs($w['a'])->getJson(route('workstation.live.student-version'))->json('version');

        $this->allocator()->startTermination($w['reqA'], WorkstationHistory::ACTOR_CGS, $this->cgs, WorkstationAllocator::REASON_TERMINATION_DEFERMENT);

        $this->assertNotSame($v0, $this->actingAs($w['a'])->getJson(route('workstation.live.student-version'))->json('version'));
    }

    // =====================================================================
    // Part 3: nothing is ever served stale
    // =====================================================================

    public function test_every_live_page_and_json_endpoint_is_no_store(): void
    {
        $w = $this->world();
        $roomId = $this->room->id;

        $cgsUrls = [
            route('workstation.cgs.operations'),
            route('workstation.cgs.operations', ['key_status' => 'requested']),
            route('workstation.cgs.operations', ['q' => 'live']),
            route('workstation.cgs.block', 'N2'),
            route('workstation.cgs.room', $roomId),
            route('workstation.cgs.seat', $w['seatB']),
            route('workstation.cgs.insights'),
            route('workstation.cgs.live.version'),
            route('workstation.cgs.live.stats'),
            route('workstation.cgs.live.insights'),
            route('workstation.assistant.suggestions'),
        ];
        foreach ($cgsUrls as $url) {
            $this->assertNoStore($this->actingAs($this->cgs)->get($url)->assertOk(), $url);
        }

        $studentUrls = [route('workstation.select'), route('workstation.live.student-version'), route('workstation.assistant.suggestions')];
        foreach ($studentUrls as $url) {
            $this->assertNoStore($this->actingAs($w['a'])->get($url)->assertOk(), $url);
        }

        // The assistant's JSON endpoints.
        $this->assertNoStore($this->actingAs($this->cgs)->postJson(route('workstation.assistant.ask'), ['message' => 'how many seats are available'])->assertOk(), 'assistant.ask');
        $this->assertNoStore($this->actingAs($this->cgs)->postJson(route('workstation.assistant.suggestion'), ['id' => 'c2_almost_full'])->assertOk(), 'assistant.suggestion');
    }

    protected function assertNoStore($response, string $what): void
    {
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), "{$what} is not no-store");
        $this->assertStringContainsString('max-age=0', (string) $response->headers->get('Cache-Control'), "{$what} lacks max-age=0");
        $this->assertSame('no-cache', $response->headers->get('Pragma'), "{$what} lacks Pragma: no-cache");
        $this->assertNull($response->headers->get('ETag'), "{$what} carries an ETag");
    }

    public function test_the_middleware_applies_only_to_this_modules_routes(): void
    {
        $this->actingAs($this->cgs);

        $this->assertStringNotContainsString('no-store', (string) $this->get('/dashboard')->headers->get('Cache-Control'));
    }

    public function test_the_pages_carry_the_live_hooks(): void
    {
        $w = $this->world();

        $landing = $this->actingAs($this->cgs)->get(route('workstation.cgs.operations'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="wsl-config"[^>]*data-kind="cgs"[^>]*data-version="c[0-9a-f]{16}"/s', $landing);
        $this->assertStringContainsString('data-stats-url="'.route('workstation.cgs.live.stats').'"', $landing);
        $this->assertStringContainsString('data-interval="15000"', $landing);
        $this->assertStringContainsString('chloe-workstation-live.js', $landing);

        $this->assertStringContainsString('data-live-region', $this->actingAs($this->cgs)->get(route('workstation.cgs.operations', ['key_status' => 'requested']))->getContent());
        $this->assertStringContainsString('data-live-region', $this->actingAs($this->cgs)->get(route('workstation.cgs.room', $this->room->id))->getContent());
        $this->assertStringContainsString('data-live-region', $this->actingAs($this->cgs)->get(route('workstation.cgs.seat', $w['seatB']))->getContent());

        $student = $this->actingAs($w['a'])->get(route('workstation.select'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="wsl-config"[^>]*data-kind="student"[^>]*data-version="s[0-9a-f]{16}"/s', $student);
        $this->assertStringContainsString('data-interval="20000"', $student);
        $this->assertStringContainsString('data-live-region', $student);
        $this->assertStringNotContainsString('data-stats-url', $student, 'a student page never points at a CGS endpoint');
    }

    public function test_the_page_script_has_no_inline_handlers_or_inline_styles(): void
    {
        foreach ([
            $this->actingAs($this->cgs)->get(route('workstation.cgs.operations'))->getContent(),
            $this->actingAs($this->cgs)->get(route('workstation.cgs.block', 'N2'))->getContent(),
        ] as $html) {
            $start = strpos($html, 'id="wsl-config"');
            $slice = substr($html, $start, strpos($html, 'chloe-workstation-live.js', $start) - $start);
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+=/i', $slice);
            $this->assertStringNotContainsString(' style=', $slice);
        }

        $js = file_get_contents(public_path('js/chloe-workstation-live.js'));
        $this->assertStringNotContainsString('new WebSocket', $js);
        $this->assertStringNotContainsString('new EventSource', $js);
        $this->assertStringNotContainsString('XMLHttpRequest(', $js);
    }

    // =====================================================================
    // Part 4.8: privacy, auth, throttle
    // =====================================================================

    public function test_the_live_endpoints_return_no_personal_data(): void
    {
        $w = $this->world();
        // A student with an unmistakable name, email and matric, on a seat, with a key.
        [$who] = $this->studentWithSeat('77', LockerKey::STATUS_REQUESTED);
        $who->update(['name' => 'Zainab Quentin Wirawan', 'email' => 'zainab.quentin@test.my', 'matric_no' => '22077777']);
        $profile = \App\Modules\Chloe\Models\DemoStudentProfile::create([
            'matric_no' => '22088888', 'name' => 'Hafizuddin Xavier Tan', 'email' => 'hafiz.xavier@utp.edu.my', 'gender' => 'male',
            'programme' => 'MSc', 'status' => 'active', 'candidacy_expiry_date' => now()->addYear(),
        ]);
        $seat = $this->seat('78', Workstation::STATUS_OCCUPIED);
        WorkstationRequest::create(['workstation_id' => $seat->id, 'demo_profile_id' => $profile->id, 'status' => 'confirmed', 'requested_at' => now()]);

        $needles = ['Zainab', 'Quentin', 'Wirawan', 'zainab.quentin', '22077777', 'Hafizuddin', 'Xavier', 'hafiz.xavier', '22088888', 'test.my', 'utp.edu.my', 'Live Student'];

        $bodies = [
            $this->actingAs($this->cgs)->get(route('workstation.cgs.live.version'))->assertOk()->getContent(),
            $this->actingAs($this->cgs)->get(route('workstation.cgs.live.stats'))->assertOk()->getContent(),
            $this->actingAs($this->cgs)->get(route('workstation.cgs.live.insights'))->assertOk()->getContent(),
            $this->actingAs($who)->get(route('workstation.live.student-version'))->assertOk()->getContent(),
        ];
        foreach ($bodies as $i => $body) {
            foreach ($needles as $needle) {
                $this->assertStringNotContainsString($needle, $body, "live endpoint #{$i} leaked '{$needle}'");
            }
            $this->assertDoesNotMatchRegularExpression('/\b\d{8}\b/', $body, "live endpoint #{$i} holds an 8-digit number");
            $this->assertDoesNotMatchRegularExpression('/[\w.+-]+@[\w-]+\.[\w.-]+/', $body, "live endpoint #{$i} holds an email");
        }

        // The version and stats endpoints are numbers only.
        $stats = $this->actingAs($this->cgs)->getJson(route('workstation.cgs.live.stats'))->json();
        $this->assertEqualsCanonicalizing(['version', 'server_time', 'stats', 'rooms'], array_keys($stats));
        $this->assertEqualsCanonicalizing(['version', 'server_time'], array_keys($this->actingAs($this->cgs)->getJson(route('workstation.cgs.live.version'))->json()));
        $this->assertEqualsCanonicalizing(['version', 'server_time'], array_keys($this->actingAs($who)->getJson(route('workstation.live.student-version'))->json()));
    }

    public function test_the_student_version_is_an_opaque_hash(): void
    {
        $w = $this->world();

        $version = $this->actingAs($w['a'])->getJson(route('workstation.live.student-version'))->json('version');

        $this->assertMatchesRegularExpression('/^s[0-9a-f]{16}$/', $version);
    }

    public function test_guests_get_401_on_every_live_endpoint(): void
    {
        foreach ([route('workstation.cgs.live.version'), route('workstation.cgs.live.stats'), route('workstation.cgs.live.insights'), route('workstation.live.student-version')] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
    }

    public function test_roles_are_enforced_with_403(): void
    {
        $w = $this->world();

        $supervisor = $this->supervisor();

        foreach ([route('workstation.cgs.live.version'), route('workstation.cgs.live.stats'), route('workstation.cgs.live.insights')] as $url) {
            $this->actingAs($w['a'])->getJson($url)->assertForbidden();
            $this->actingAs($supervisor)->getJson($url)->assertForbidden();
        }

        $this->actingAs($this->cgs)->getJson(route('workstation.live.student-version'))->assertForbidden();
        $this->actingAs($supervisor)->getJson(route('workstation.live.student-version'))->assertForbidden();
    }

    public function test_the_live_endpoints_are_throttled(): void
    {
        config(['assistant.rate_limits.live_requests_per_minute' => 3]);

        foreach (range(1, 3) as $i) {
            $this->actingAs($this->cgs)->getJson(route('workstation.cgs.live.version'))->assertOk();
        }
        $this->actingAs($this->cgs)->getJson(route('workstation.cgs.live.version'))->assertStatus(429);
        // The limit is shared across the three CGS endpoints and keyed per user.
        $this->actingAs($this->cgs)->getJson(route('workstation.cgs.live.stats'))->assertStatus(429);
        $this->actingAs($this->supervisor('sup2@test.my'))->getJson(route('workstation.cgs.live.version'))->assertForbidden();
    }

    public function test_the_default_limit_leaves_room_for_several_open_pages(): void
    {
        // One page polls the version every 15 s = 4 a minute; the default must allow far more than a few tabs.
        $this->assertGreaterThanOrEqual(30, (int) config('assistant.rate_limits.live_requests_per_minute'));

        foreach (range(1, 20) as $i) {
            $this->actingAs($this->cgs)->getJson(route('workstation.cgs.live.version'))->assertOk();
        }
    }

    // =====================================================================
    // The version is cheap
    // =====================================================================

    public function test_the_cgs_version_is_one_query(): void
    {
        $this->world();

        \DB::flushQueryLog();
        \DB::enableQueryLog();
        WorkstationLiveVersion::cgs();
        $queries = \DB::getQueryLog();
        \DB::disableQueryLog();

        $this->assertCount(1, $queries, 'the version must be a single round trip');
        $this->assertStringNotContainsString('join', strtolower($queries[0]['query']));
        $this->assertSame(0, substr_count(strtolower($queries[0]['query']), 'group by'));
    }
}
