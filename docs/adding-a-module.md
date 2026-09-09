# Adding a module

Worked example: a **Conference Attendance** request that a student submits,
their supervisor endorses, and CGS approves.

Everything below happens inside **your own folder**. Substitute your name.

---

## 0. Claim a key

Add your `module_type` to [`module-keys.md`](module-keys.md) so nobody else
takes it. The registry throws on a duplicate.

## 1. Declare the chain

`app/Modules/<You>/Workflows/ConferenceWorkflow.php`

```php
<?php

namespace App\Modules\<You>\Workflows;

use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\<You>\Models\ConferenceDetail;

class ConferenceWorkflow implements WorkflowModule
{
    public function key(): string   { return 'conference'; }
    public function label(): string { return 'Conference Attendance'; }

    public function stages(?Application $application = null): array
    {
        // $application is null when the registry asks for the full set of
        // stages. With no conditional routing, one chain answers both.
        return [
            new Stage('supervisor', 'Lecturer/Supervisor', Role::SUPERVISOR,    'endorsed',
                      queueTitle: 'Pending My Endorsement'),
            new Stage('cgs',        'Non-Executive CGS',   Role::NON_EXEC_CGS, 'approved',
                      queueTitle: 'Pending My Approval'),
        ];
    }

    public function summary(Application $application): string
    {
        $d = ConferenceDetail::where('application_id', $application->id)->first();

        return $d ? $d->conference_name.' — '.$d->location : 'Conference request';
    }

    public function createRoute(): ?string { return 'conference.create'; }
    public function queueRoute(): string   { return 'conference.queue'; }
}
```

## 2. Register it

`app/Modules/<You>/ModuleProvider.php`

```php
public function boot(ModuleRegistry $registry): void
{
    $registry->register(new Workflows\ConferenceWorkflow());
}
```

## 3. Your table

`app/Modules/<You>/Database/Migrations/2026_03_01_000100_create_conference_details_table.php`

```php
Schema::create('conference_details', function (Blueprint $table) {
    $table->id();
    $table->foreignId('application_id')->constrained()->cascadeOnDelete();
    $table->string('conference_name', 255);
    $table->string('location', 255);
    $table->date('starts_on');
    $table->date('ends_on');
    $table->timestamps();
});
```

Your detail table **only**. Never add to `users`, `applications`,
`approval_history` or `application_documents`.

Date-prefix your filename later than `2026_01_01` so Core's tables exist first.

## 4. Model

```php
class ConferenceDetail extends Model
{
    protected $fillable = ['application_id', 'conference_name', 'location', 'starts_on', 'ends_on'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }
}
```

## 5. Controller

```php
class ConferenceController extends Controller
{
    use ApprovesApplications;   // approve/reject comes free

    protected function moduleKey(): string { return 'conference'; }

    public function create()
    {
        return view('<you>::conference.form');
    }

    public function store(Request $request, WorkflowEngine $engine)
    {
        $data = $request->validate([
            'conference_name' => ['required', 'string', 'max:255'],
            'location'        => ['required', 'string', 'max:255'],
            'starts_on'       => ['required', 'date', 'after_or_equal:today'],
            'ends_on'         => ['required', 'date', 'after_or_equal:starts_on'],
        ]);

        $application = DB::transaction(function () use ($request, $data, $engine) {
            $application = Application::create([
                'student_id'      => $request->user()->id,
                'submitted_by_id' => $request->user()->id,
                'module_type'     => $this->moduleKey(),
                'status'          => Application::STATUS_DRAFT,
            ]);

            ConferenceDetail::create(['application_id' => $application->id] + $data);

            return $engine->submit($application);   // hands it to stage 1
        });

        return redirect()->route('applications.index')
            ->with('status', "Application #{$application->id} submitted.");
    }

    public function queue(Request $request, WorkflowEngine $engine)
    {
        $queue = $this->queueFor($request, $engine, ['documents']);

        $details = ConferenceDetail::whereIn('application_id', $queue['applications']->pluck('id'))
            ->get()->keyBy('application_id');

        return view('<you>::conference.queue', $queue + ['details' => $details]);
    }
}
```

## 6. Routes

`app/Modules/<You>/routes.php` — loaded automatically.

```php
Route::middleware('auth')->group(function () {
    Route::middleware('role:'.Role::STUDENT)->group(function () {
        Route::get('/conference/new', [ConferenceController::class, 'create'])->name('conference.create');
        Route::post('/conference',    [ConferenceController::class, 'store'])->name('conference.store');
    });

    Route::middleware('role:'.Role::SUPERVISOR.','.Role::NON_EXEC_CGS)->group(function () {
        Route::get('/conference/queue', [ConferenceController::class, 'queue'])->name('conference.queue');
        Route::post('/conference/{application}/decide', [ConferenceController::class, 'decide'])->name('conference.decide');
    });
});
```

## 7. Views

Three files under `app/Modules/<You>/Resources/views/conference/`:

**`form.blade.php`** — extend `core::layouts.app`, use `.card.card-wide`.

**`queue.blade.php`** — the whole file:

```blade
@extends('core::layouts.app')
@section('title', 'Conference — ' . $stage->queueTitle())

@section('content')
    @include('core::partials.queue', [
        'moduleLabel' => 'Conference Attendance',
        'decideRoute' => 'conference.decide',
        'detailView'  => '<you>::conference._detail',
    ])
@endsection
```

**`_detail.blade.php`** — just your fields; the card, the student's name, the
attachments and the approve/reject form are supplied by the shared partial:

```blade
@php($detail = $details[$application->id] ?? null)

@if ($detail)
    <p>Conference: {{ $detail->conference_name }}</p>
    <p>Location: {{ $detail->location }}</p>
    <p>Dates: {{ $detail->starts_on->format('j M Y') }} — {{ $detail->ends_on->format('j M Y') }}</p>
@endif
```

## 8. Run it

```bash
php artisan migrate
php artisan serve
```

Log in as `student@utp.edu.my`. **Conference Attendance** is already in the
sidebar — nothing was added to a shared file to put it there.

---

## Uploads

```php
'supporting_document' => DocumentStore::rules(required: true),
```

then, inside the transaction:

```php
$documents->attach($application, $request->file('supporting_document'), 'Supporting Document');
```

Type-inject `DocumentStore $documents`. Never call `move_uploaded_file()`.

## Testing a chain end to end

1. `student@utp.edu.my` submits.
2. `supervisor@utp.edu.my` → sidebar → your module → Approve.
3. `cgs@utp.edu.my` → Approve.
4. Back as the student: the stepper is complete.
5. http://localhost:8025 — three notification emails.

Run `php artisan queue:work`, or set `QUEUE_CONNECTION=sync` in `.env`, or the
emails sit in the queue.
