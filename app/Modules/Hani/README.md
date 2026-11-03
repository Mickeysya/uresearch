# Nur Hani Sofia Binti Mohd Azam (22001418)

**Scope:** Examiner Nomination & Matching · Conflict Detection · Re-viva Monitoring. Constraint-based rule engine.

## Built

- **Examiner pool** — `examiners` with the four-state machine (Assigned,
  On Gap, Available, Unavailable). State is *derived* in `Examiner::state()`
  rather than stored, so an examiner comes off gap automatically at 90 days
  with no job needing to run.
- **Examiner Nomination** — a supervisor names **four** seats for one of their
  own candidates: internal main and backup, external main and backup. The
  internal pair must come from the **candidate's own department**, checked in
  `store()` (no foreign key can say "the same department as the student on
  this application") and shown in the form, where picking a candidate hides
  every internal examiner from anywhere else. Touchpoint 1 (individual
  eligibility at nomination time) is enforced in the dropdown and again on
  submit.
- **The chain** (agreed 2026-09-22, `ExaminerNominationWorkflow`) —
  six stages, because what travels along it stops being one nomination after
  the first stage:

  | Stage | Who | What they do |
  |---|---|---|
  | `academic_exec` | Academic Executive | clears their own department's panels (department-scoped) |
  | `cgs_compile` | Senior Executive CGS | merges every department into one list, checks for clashes |
  | `senior_director` | Senior Director CGS | verifies, or sends it back to the department |
  | `dean` | Dean of PGR | approves, or sends it back to the department |
  | `cgs_final` | Senior Executive CGS | holds the finalised report |
  | `cgs_release` | Non-Executive CGS | releases the final list |

- **Sending a list back** — the Senior Director and the Dean do not reject a
  list they will not sign off; they return it to `academic_exec` with a
  mandatory reason (`WorkflowEngine::returnTo()`, added to Core for this).
  The candidate keeps one application and one audit trail however many times
  it loops, and the chain replays forward, so CGS re-compiles before it
  reaches the Dean again. Both the candidate and that department's Academic
  Executives are emailed.
- **The compiled report** — `/examiner-nomination/report`, readable by the
  Academic Executive (their own department only), CGS, the Senior Director,
  the Dean and the Non-Executive. Grouped faculty → department → candidate,
  with cross-department clashes flagged inline. `?format=xlsx` or `csv` on
  `/examiner-nomination/report/export` is the same rows as a file, off one
  `ExaminerListExport`, scoped by the same query as the screen — an Academic
  Executive cannot export a department they cannot read.
- **Examiner lifecycle** — `ExaminerNominationController::decide()` ties up
  every seat on the panel, but **only when the chain finishes**, not at the
  first approval: six stages now sit between the supervisor and the release,
  and an early tie-up would hold four examiners for 180 days while the list
  was still being argued over. The AE's "Pending Evaluation" screen
  (`/examiner-nomination/pending-evaluation`) marks an evaluation complete,
  which clears `assigned_until` and stamps `last_examination_date` — the
  event that actually starts the 90-day gap.
- **Conflict detection, touchpoint 2** — `/examiner-nomination/conflicts`
  (AE) compiles nominations by the examiner's faculty and flags any examiner
  nominated by more than one department. Read-only; never auto-rejects. The
  same check runs inline on the compiled report, which is where CGS sees it
  at the `cgs_compile` stage.
- **Examiner admin** — `/examiners` (Non-Exec CGS): add an examiner, toggle
  Unavailable. Internal and external are kept as two lists behind a tab
  strip, because CGS keeps two spreadsheets and the external one carries nine
  columns the internal one has no equivalent of: faculty approval reference,
  institution, technical or research, UTP cluster, area of expertise, years
  of experience, MSc and PhD graduates supervised, and the first examination
  date. All nullable, all external only. `institution` is required for an
  external examiner, and `store()` strips `Examiner::EXTERNAL_FIELDS` when an
  internal one is saved, so an internal row can never hold half an external
  record. The add form is a **two-step wizard** (Examiner / External record)
  — the external columns took it to thirteen fields, past the eight
  `docs/conventions.md` puts the line at. For an internal examiner the
  external block is `hidden` **and** `disabled`: a disabled fieldset submits
  none of its controls, so a half-typed external record cannot reach the
  controller at all, and the generated review cannot list values that are not
  going to be saved. `Arr::except` in `store()` stays as the guard that does
  not depend on the browser.
  Three columns on the CGS sheets are deliberately *not* stored:
  "Date 2nd" is `last_examination_date`, which already drives the 90-day gap;
  "Student Name" is derived from `examiner_nominations`; and "Remark" is what
  `unavailable_reason` already shows.
- **Re-viva monitoring** — CGS Staff logs the re-corrected thesis once it
  reaches them (`reviva.create`, Non-Exec CGS, with an eligibility-aware
  student picker like the examiner dropdown), which stamps `resubmission_at`
  and computes the 6-month correction / 1-year hardbound deadlines. Not a
  student self-service upload — see `ReVivaWorkflow`'s docblock. The AE
  advances a 4-stage stepper (Report sent → Under panel review → Report
  received → Consolidation scheduled), then records the 5-level outcome on a
  separate screen (`/re-viva/outcomes`) that never touches
  `applications.status`. A level-4 outcome doesn't loop the Stage graph; it
  opens the door for CGS to log the next cycle, which links back to the
  prior one via `re_viva_details.previous_cycle_id`.

## Examiner suggestions (how the matching works)

The nomination form lists **Suggested examiners** for each seat type, top 5,
each with its score, the terms it matched on ("matched on: anomaly detection,
time series"), how long since the examiner last examined, and any
conflict-of-interest flag. One click fills a seat; the dropdowns stay free.

**Method: TF-IDF with cosine similarity, in plain PHP** (`Support/Matching/`).
No external AI service, and nothing leaves the server.

1. **Documents.**
   - *Examiner:* area of expertise, expertise keywords and publication titles.
   - *Thesis:* the title, plus the abstract and keywords once the Notice of
     Thesis Submission carries them. Until then it falls back to the
     nomination's title.
2. **Terms** (`Tokeniser`, `Stemmer`): lower case, stop words removed
   (including words every thesis title has, such as "study" and "approach"),
   and a light suffix-stripper so "detection" meets "detecting".
   - A multi-word keyword ("anomaly detection") is also kept whole as a
     phrase term.
   - A title's adjacent word pairs count as phrases only where some examiner
     has that phrase.
3. **Weights.**
   - Term frequency is log-scaled (1 + ln tf).
   - Inverse document frequency, ln((N+1)/(df+1)) + 1, is taken over the
     **eligible** pool, so a word everyone shares counts for little and a rare
     one counts for a lot.
   - Phrases are boosted by `hani.matcher.phrase_boost`.
4. **Score:** the cosine of the two vectors, shown as 0–100.
5. **Explanation:** the overlapping terms, ordered by their share of the score.
   A matched phrase stands in for its own words.
6. **Who is ranked:** only examiners who could be nominated now. That means
   Available, and for the internal seats from the candidate's own department
   (`Support/Departments`). A suggestion is therefore never something the form
   would refuse.
7. **Tie-breakers** (`hani.matcher.weights`, provisional): fewer main-seat
   examinations in the last 12 months, longer since the last examination, and
   for external examiners years of experience. These spread the load without
   overriding expertise.
8. **Cache:** the term statistics are cached under a key built from the pool
   itself, plus today's date because gaps run out:
   - examiners
   - expertise keywords (which touch their examiner)
   - publications (which also touch their examiner)

   Any change produces a new key, and the table is rebuilt on next use.

**Who sees it:**
- supervisors, for their own candidates
- Academic Executives, for their own department
- the Senior Executive CGS, for anyone

Never students (`ExaminerSuggestionController`).

**Where an embeddings model would plug in.** `ExaminerMatcher` is the seam.
A sentence-embedding model run locally (for example a small multilingual
MiniLM served over HTTP on the same host) would implement
`rank($query, $candidates)`:
- embed the thesis and each examiner document
- cache the examiner vectors under the same pool-version key
- score by cosine similarity

Bind it in `ModuleProvider::register()` instead of `TfIdfMatcher`. Keep the
explanation: report the expertise keywords closest to the thesis, so a score
is never shown without its reason.

## Still to build

Nothing from the original TODO list remains unscoped. Candidates for
follow-up, not currently tracked as required:
- A student-facing view of their own re-viva cycle history (currently they
  only see the generic tracking page's stepper for whichever cycle is open).
- Automated reminders as correction/hardbound deadlines approach — `TODO.md`'s
  cross-cutting section notes scheduled commands are still a gap generally.

## Tests

`tests/Feature/Hani/` — 24 cases.

| File | Guards |
|---|---|
| `ExaminerNominationTest` | the four-seat panel, the internal-examiner department rule, the internal/external seat types, that only a FINISHED chain ties the panel up, the Senior Director's and the Dean's return-to-department path (including that a reason is required and that the first stage cannot send anything back), and that the compiled report is scoped by department for an Academic Executive and forbidden to a student |
| `ExaminerPoolTest` | the internal/external split: which columns each list shows, tab-scoped counts, the derived student column, the external-field strip on an internal save, `institution` being required for an external examiner, and the add form's stepper contract (two steps, external block disabled for an internal examiner) |
| `ReVivaTest` | the four-stage stepper and the level-based outcome |

Run them with `./vendor/bin/sail artisan test --filter=Hani`.

## Layout

```
Hani/
├── ModuleProvider.php          registers your workflows
├── routes.php                  loaded automatically
├── Workflows/                  one class per application type
├── Models/                     your detail tables
├── Http/Controllers/           your controllers
├── Database/Migrations/        your tables only
└── Resources/views/            view('hani::your.view')
```

## The three rules

1. **Only edit files inside this folder.** If you need something from
   `Core/`, raise it with the team — it affects all six of us.
2. **Your `module_type` must be unique.** Claim it in `docs/module-keys.md`.
3. **Never write `status` or `current_stage` yourself.** Call
   `WorkflowEngine::submit()`, `::decide()` or `::returnTo()`.

See `docs/adding-a-module.md` for the full walkthrough.
