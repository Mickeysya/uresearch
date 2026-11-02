# Study Candidacy assistant, and the two domains

One engine, **two separate domains**: `workstation` and `candidacy`. The engine is shared: the same floating button and chat panel, the same endpoints (`/workstation/assistant/...`), the same Gemini setup, key and free-tier limits, the same router
(one model call returns `{id, params, confidence, alternatives, clarify}`; the model never writes a number, a name or a date), the same privacy rules, the same fallback (keyword rules, then topic text, then the busy message with chips)
and the same "Interpreted as:" line and "Did you mean" chips. Everything that is **content** belongs to exactly one domain, and one domain never answers the other.

## Domains (`Assistant\AssistantDomains`, `config/assistant.php` -> `domains` and `domain_pages`)

| | workstation | candidacy |
|---|---|---|
| Pages | `workstation.*` routes (student pages, CGS Workstation Management, Insights ...) | `candidacy.*` and `candidacy-appeal.*` routes (My Study Candidacy, appeal pages, Approval Portal, Operations, Insights, Dismissal List, Dismissal Upload, My Signature) |
| Title, placeholder | Workstation Assistant, "Ask about workstations or locker keys" | Study Candidacy Assistant, "Ask about your candidacy or appeals" |
| Roles | student, CGS | student, approver, CGS |
| Catalogue | `Catalogue::forRole($role, 'workstation')` | `Catalogue::forRole($role, 'candidacy')` |
| Suggested questions (9 per role) | `SuggestedQuestions` | `CandidacyAssistant::suggestions()` (answered by catalogue id, no model call) |
| Guidance topics | `docs/knowledge/workstation/{student,cgs}/` | `docs/knowledge/candidacy/` |
| Router prompt and examples | `GeminiModel::routerInstruction` (workstation head), `RouterFewShots::workstation()` | the candidacy head and `RouterFewShots::candidacy()` |
| Evaluation set | `set: workstation` | `set: candidacy` (and `cross`) |
| Log | `assistant_logs.domain = workstation` | `candidacy` |

**Which page is in which domain** is decided in one place: the first matching pattern of `config('assistant.domain_pages')` by route name. To give a new page a domain, add one line there.
The widget is rendered with its domain, its page and a **token** (an HMAC of domain, user and page); the server rejects a request whose token does not match what it claims, whose page is not mapped to the claimed domain,
or whose role the domain does not serve (an approver has no Workstation). The model is only ever given the catalogue of the current domain, and a catalogue id of the other domain is rejected even if the model names it.

**Out-of-domain questions.** `config('assistant.domains.<d>.strong_terms')` lists words that clearly belong to a domain (workstation: seat, workstation, locker, key, block, room, vacate, Term of Use ...; candidacy: appeal, approver, supervisor,
chair, Dean, memo, Softbound, dismissal, reminder, signature, extension, candidacy ...). A question with strong terms of the OTHER domain and none of the current one is answered with the redirect **without calling the model**: on a Workstation
page "I can only answer questions about workstations and locker keys here. For Study Candidacy questions, please use the assistant on the Study Candidacy pages." and on a candidacy page "I can only answer questions about Study Candidacy
(appeals, approvals, reminders, expiry and dismissal) here. For workstation or locker key questions, please use the assistant on the Workstation pages.", each with a link to the user's own page in the other domain (student: My Study Candidacy /
the Workstation page; approver: the Approval Portal / the Dashboard, because an approver has no Workstation page; CGS: Operations / Workstation Management) and the CURRENT domain's chips below it. Words that exist in both worlds
(expiry, student, CGS, programme) never redirect by themselves, and a question with strong terms of the current domain is routed normally. "Candidacy" next to an expiry word on a Workstation page ("when does my candidacy expire") goes to the
Workstation catalogue first (a workstation loan runs to the candidacy expiry date); if that finds no entry (the router returns no match), the redirect follows.

The chat history lives only in the page (nothing is stored), so the two domains can never share one; the one thing kept per domain is the greeting flag (`wsaGreetingShown:<domain>`), and New chat clears both.

## Roles (`Assistant\AssistantRoles`)

| Role | Who | Sees |
|---|---|---|
| `student` | students | their own candidacy and appeal, the candidacy guidance, and the Workstation student entries |
| `approver` | Supervisor, Department Chair, Dean | only the appeals named on them (the Approval Portal queries), the approver guidance |
| `cgs` | `candidacy.cgs_roles` | aggregates (the Operations, Insights and Dismissal List numbers), the CGS guidance, and the Workstation CGS entries |
| `cgs_approver` | a CGS user who also holds a candidacy staff profile | both sets and nothing else |

Every entry carries `domain` (`workstation` or `candidacy`) and `roles`. The page the question is asked on gives only a **prior** (that domain's entries first, and its own six suggested questions); no entry is hidden by it.
A role never receives another role's entries: the router only sees its role's catalogue, and the pipeline re-checks the chosen id against the asking role. `CandidacyAssistantTest` checks every role against every entry.

## What the candidacy domain answers (candidacy entries, plus actions)

Counts today (candidacy domain, data lookups + guidance topics, without the five refusals): **student 31**, **approver 31**, **CGS 50** (63 intents in all; see the matrix below). Workstation entries are unchanged.

- **Student, own record only:** expiry date and days left; extension months used and left; whether an appeal is possible now (the same `eligibilityProblems()` as the New Appeal button, with the reason); appeal number, status, step and who has it;
  why an appeal was returned; when the next reminder is sent (the reminder engine's own decision); whether the Softbound is recorded ("Demo data" is shown for the demo overlay).
- **Approver, own appeals only:** how many wait for me (the portal's "Pending my action"), the longest wait, how many waited 5 or 10 days or more, how many I approved or returned this month, how many are ongoing.
- **CGS:** appeals per step; returned, approved, rejected and closed-expired counts; the five longest waits (linked); appeals waiting 10 days or more; average waiting per step; students expiring within 1, 2 and 3 months; how many took no action;
  students at 12 of 12 months; appeals by department; reminders sent this and last month (or `window`: this_month, last_month, last_30_days); the reminder response rate; dismissal candidates, exported and not; dismissed this month.
- **Guidance (38 intents, `docs/knowledge/candidacy/*.md`, one section per intent and role):** what candidacy is and how long; the Handbook rules on extension, deferment, dismissal and the Softbound; where to see the full appeal list; when and how to appeal; documents; the approval route; how to approve or return; statuses; returns, resubmitting and the Dean's rejection; the return reasons; the 12-month limit; the deadline; withdrawal; after approval and the memo; reminders; notifications; My Signature; the portal and the waiting-day colours; the appeal rules; FAQ; outcome prediction; and for CGS: reading Operations, Insights and the Dismissal List, export and Registry upload, the end-to-end process, and what is not built yet.

Every number comes from the same query or class the page uses (`CandidacyOperationsQuery`, `CandidacyPortalQuery`, `CandidacyStatistics`, `CandidacyDismissalList`, `CandidacyAppealService::eligibilityProblems`, `StudentStatusReader`); tests prove the assistant's numbers equal the pages'.
Lists and numbers are produced by the backend and never go to the model.

## What it never does

It explains and shows data. It **never** approves, returns, rejects, submits or withdraws an appeal, sends reminders, exports, uploads or dismisses, and never predicts a decision. Such requests are refused with a short reason and a link to the page that does it
(`cand_action_decide`, `cand_action_submit`, `cand_action_reminders`, `cand_action_export_upload`, `cand_action_dismiss`). "Will my appeal be approved?" gets: the approvers and the Dean decide; I can explain the rules and the current status.

## Answers depend on the role (the intent x role matrix)

The knowledge base is five files in `docs/knowledge/candidacy/`; each guidance answer is a `## cand_<intent>` section, shown as written (never rewritten by the model) and ending with a `Source:` line.

| File | Holds | Shown to |
|---|---|---|
| `00-system-flow.md` | the confirmed system design (source "UResearch Study Candidacy design (confirmed with CGS)") and the answers that are the same for everyone | every role |
| `01-handbook.md` | only the in-scope Handbook items, each with its section and page ("Postgraduate Student Handbook 2025, section 2.1, p.7") | every role |
| `10-student.md` | the student's version of an answer (their pages and buttons only) | students |
| `20-approver.md` | the approver's version (Approval Portal, Review, Approve / Return, Reject for the Dean, My Signature) | Supervisor, Chair, Dean |
| `30-cgs.md` | the CGS version (Operations, Insights, Dismissal List, Dismissal Upload, CGS Verification) | CGS |

**A role's answer for an intent is that role's own section, else a shared one, else the intent does not exist for the role** ("not for this role"). Which roles may ask an intent is therefore never typed in code: it is whatever sections the files hold
(`CandidacyAssistant::knowledge()` and `topicRoles()`). The router only sees the asking role's entries, the pipeline re-checks the chosen id, and `CandidacyAssistant::sourceText($topic, $role)` can only return that role's text.
A CGS user who is also an approver (`cgs_approver`) gets the CGS section first, then the approver one.

When a question names a page or button that belongs to another role ("how do I read the Operations page" from a student, "how do I submit an appeal" from an approver), the reply is "That is for CGS, so I can't answer it for your account. As a student,
you can ask me about the questions below." with the asking role's own suggested questions, never the other role's instructions. Each role has 9 suggested questions, answered without a model call.

| Intent | Student | Approver | CGS |
|---|---|---|---|
| `cand_see_all_appeals` | yes | yes | yes |
| `cand_when_how_appeal` | yes | not for this role | yes |
| `cand_required_documents` | yes | yes | yes |
| `cand_approval_route` | yes | yes | yes |
| `cand_after_return_rejection` | yes | not for this role | not for this role |
| `cand_return_reasons` | yes | yes | yes |
| `cand_dean_rejection` | yes | yes | yes |
| `cand_resubmit` | yes | yes | yes |
| `cand_after_approval` | yes | yes | yes |
| `cand_reminders` | yes | not for this role | yes |
| `cand_notifications` | yes | yes | yes |
| `cand_if_expires` | yes | not for this role | yes |
| `cand_how_decide` | not for this role | yes | yes |
| `cand_approver_rules` | not for this role | yes | yes |
| `cand_my_signature` | not for this role | yes | yes |
| `cand_return_effect` | not for this role | yes | yes |
| `cand_portal_guide` | not for this role | yes | yes |
| `cand_waiting_colours` | not for this role | yes | yes |
| `cand_cgs_operations_guide` | not for this role | not for this role | yes |
| `cand_cgs_insights_guide` | not for this role | not for this role | yes |
| `cand_cgs_dismissal_list_guide` | not for this role | not for this role | yes |
| `cand_cgs_export_registry` | not for this role | not for this role | yes |
| `cand_cgs_registry_upload` | not for this role | not for this role | yes |
| `cand_cgs_process_summary` | not for this role | not for this role | yes |
| `cand_cgs_not_built` | not for this role | not for this role | yes |
| `cand_status_meanings` | yes | yes | yes |
| `cand_twelve_month_limit` | yes | yes | yes |
| `cand_deadline` | yes | yes | yes |
| `cand_withdraw` | yes | yes | yes |
| `cand_appeal_rules` | yes | yes | yes |
| `cand_faq` | yes | yes | yes |
| `cand_outcome_prediction` | yes | yes | yes |
| `cand_what_is_candidacy` | yes | yes | yes |
| `cand_hb_extension` | yes | yes | yes |
| `cand_deferment_mode_programme` | yes | yes | yes |
| `cand_hb_dismissal` | yes | yes | yes |
| `cand_softbound_ends` | yes | yes | yes |
| `cand_my_expiry` (data) | yes | not for this role | not for this role |
| `cand_my_extension` (data) | yes | not for this role | not for this role |
| `cand_can_appeal` (data) | yes | not for this role | not for this role |
| `cand_my_appeal` (data) | yes | not for this role | not for this role |
| `cand_my_return_reason` (data) | yes | not for this role | not for this role |
| `cand_next_reminder` (data) | yes | not for this role | not for this role |
| `cand_my_softbound` (data) | yes | not for this role | not for this role |
| `cand_ap_pending` (data) | not for this role | yes | not for this role |
| `cand_ap_oldest` (data) | not for this role | yes | not for this role |
| `cand_ap_waiting_days` (data) | not for this role | yes | not for this role |
| `cand_ap_decided_month` (data) | not for this role | yes | not for this role |
| `cand_ap_ongoing` (data) | not for this role | yes | not for this role |
| `cand_cgs_in_progress` (data) | not for this role | not for this role | yes |
| `cand_cgs_pending_by_step` (data) | not for this role | not for this role | yes |
| `cand_cgs_outcome_counts` (data) | not for this role | not for this role | yes |
| `cand_cgs_oldest` (data) | not for this role | not for this role | yes |
| `cand_cgs_waiting_10` (data) | not for this role | not for this role | yes |
| `cand_cgs_avg_waiting` (data) | not for this role | not for this role | yes |
| `cand_cgs_expiring` (data) | not for this role | not for this role | yes |
| `cand_cgs_no_action` (data) | not for this role | not for this role | yes |
| `cand_cgs_twelve_of_twelve` (data) | not for this role | not for this role | yes |
| `cand_cgs_by_department` (data) | not for this role | not for this role | yes |
| `cand_cgs_reminders_sent` (data) | not for this role | not for this role | yes |
| `cand_cgs_reminder_response` (data) | not for this role | not for this role | yes |
| `cand_cgs_dismissal_candidates` (data) | not for this role | not for this role | yes |
| `cand_cgs_dismissed` (data) | not for this role | not for this role | yes |

Verified against the code: page names (Track My Applications > My Candidacy, "My Study Candidacy", Approval Portal with the tabs Pending my action, Ongoing, History; for CGS Pending my action, Decided by me and the "View all appeals" link; Candidacy Management > Operations,
Insights, Dismissal List, Dismissal Upload; My Signature), buttons (Submit an Appeal, Edit and resubmit, Download memo, Review, Approve, Return to student, Verify, Reject (final) and Confirm rejection, Save signature, Remove my signature, Export to Excel, Preview,
Confirm and dismiss), the four New Appeal steps and Sections A to D, the document limits (placeholders), the reason lists in `config/candidacy.php`, the waiting-day dots (grey under 5, amber from 5, red from 10), and the memo file name.

**Not built (answered "not available yet", `cand_cgs_not_built`):** an Excel export of the Operations list, a reminder log page, 3-day and 1-day urgent reminders, and a CGS page to change an expiry date by hand. Differences from the brief: the Softbound status
is demo data until the Softbound module's table is known; the reminders are sent at 08:00 by `candidacy:send-reminders` (the "07:30 refresh" is not in the code: 07:30 is when `candidacy:close-expired-appeals` runs, and 07:15 `candidacy:generate-dismissals`);
the old `candidacy:remind` in `routes/console.php` is a deprecated no-op (a shared file, left alone).

## Handbook scope

In scope (and nothing else from the Handbook): 2.1 p.7 durations, 2.1.1 p.8 extension, 2.5 pp.12-13 deferment, 2.6 p.13 change of mode, 2.7 pp.13-14 change of programme, 4.3 p.19 dismissal, 4.4 p.20 reinstatement (outside the system), 5.3 p.30 item ix the soft-bound thesis.
Anything else (RPD/RCS deadlines, viva, conference and progress marks, GA/GRA/TA allowances and stage gates, thesis correction, registration, probation, credit transfer, leave, supervisor change, fees, visa, upgrade, IP) is refused:
the terms are `config('assistant.domains.candidacy.out_of_scope_terms')`; when one is present and the question has no core candidacy word (candidacy, appeal, Softbound, dismissal, reminder, memo, approver) the assistant says it only covers Study Candidacy,
**without a model call**. The 12-month limit comes from the CGS process (confirmed), not from the Handbook.

## When the model is busy (the keyword fallback)

Only entries of the asking role and domain can be answered, and only with a clear match: a regex rule that names what it is about (specific rules first), or a word-overlap score of at least 3 shared words, at least 60% of the question's words and
one more than the runner-up. Otherwise the reply is "I could not match that to something I can answer. Try one of these questions, or ask it in other words." with that role's suggested questions (and "Did you mean" chips). It never shows a loosely related topic, and never
guidance written for another role.

## The request counter and `assistant:budget`

The day counter (`assistant:calls:day:<model>:<LA date>`, cache store `database`; per-minute and token keys beside it) counts HTTP requests sent to Gemini, per model, in Pacific time (Google's quota day; it resets at 16:00 in Kuala Lumpur). Caps stay below
Google's free tier: primary 12 per minute and 400 per day (Google 15 and 500), fallback 4 and 15 (Google 5 and 20). A call that never leaves because no key is configured is taken back; `assistant:check` is counted; the tests and `assistant:evaluate --stub` use an array
cache and model names that match no real model, so they can never touch the live counter.

- `php artisan assistant:budget` prints the counter, the calls logged today (`assistant_logs.model_used`), our caps and Google's limits, and warns when they disagree.
- `php artisan assistant:budget --reset` (only when `APP_ENV=local`) sets each day counter to the number of calls logged today.
- Google AI Studio's own panel is the reference for what Google counted; the log holds one row per answered question, so retries and failed attempts raise the counter above the log (a difference of a few is normal).

## Evaluation

`app/Modules/Chloe/Assistant/evaluation_questions_candidacy.php`: 353 questions (student 105, approver 89, CGS 159), at least 3 per catalogue entry, informal and misspelled wording, ten "see all appeals" paraphrases per role (the CGS one that failed in production among them),
wrong-role questions, action requests, outcome predictions, out-of-scope Handbook questions, name questions, "did you mean" triggers and window questions. `assistant:evaluate --set=candidacy` runs it live (one router call per question, paced);
`--stub=route` answers with the expected id (proves the plumbing, the role filter, the templates and the refusals), `--stub=fallback` runs the keyword fallback alone; both are offline. The report adds accuracy per role and the number of role leaks
(an id the role does not have, or an answer naming another role's page or button: `CandidacyAssistant::FORBIDDEN`).

## Privacy and PDPA notes

- Student IDs and e-mail addresses are masked before anything reaches the model; a question that contains a known student name is refused before any model call; an e-mail is never a lookup key.
- The model call never contains a name, an ID, an e-mail, an appeal number or a date of a person (tested). It only sees the masked question, the role's catalogue (invented paraphrases) and recent masked questions.
- The log stores metadata only (role, time, lookup or topic id, outcome, refusal reason, counts): no question text and no answer text (tested).
- A student sees only their own record; an approver only the appeals named on them; CGS sees aggregates and the appeal numbers of the longest-waiting appeals. Names are never shown by the assistant for lists.
- There is no server-side conversation: only the last five questions (masked) travel with each request; the session is cleared on logout and "New chat".
- Candidacy topics are shown as written (never rewritten by the model), so a "Source:" line cannot be dropped and no number can be invented.

## How to demo

1. As `student@utp.edu.my`, open My candidacy, click the robot and ask the six chips (no model call), then type "can i still appeal" or "will my appeal be approved?".
2. As `supervisor@utp.edu.my`, open the Approval Portal: "How many appeals are waiting for me?" (the same number as the Pending tab), "What can I do at my step?".
3. As `cgs@utp.edu.my`, open Operations: "How many students expire within 3 months?" (the Insights number), "How many dismissal candidates are there?", then "export the dismissal list now" (refused, with the links).
4. Ask a CGS question as the student: it is out of scope. Type a student's name: refused.

## How to add an intent

1. Write a `## cand_<id>` section in the file of every role that may ask it (or in `00-system-flow.md` / `01-handbook.md` if the answer is the same for everyone): plain text (the widget shows plain text, `•` for bullets), only that role's pages and buttons, ending with a `Source:` line.
   Use `{{cand_max_extension_months}}`-style tokens for numbers (`GuidanceLibrary::placeholderValues()`).
2. Add its question, description and 5 or 6 paraphrases to `CandidacyAssistant::topicSpecs()`; optionally a specific regex in `KEYWORDS` (the fallback), and a link in `linksForTopic()`.
3. Add 3 questions to `evaluation_questions_candidacy.php` and run `php artisan test --filter="CatalogueTest|CandidacyAssistant"`: the tests fail if an intent has no spec or the reverse, if any role's text names another role's page, or if a source line is missing.
To add a data answer: a method in `CandidacyLookups`, its name in `methods()`, and its spec in `CandidacyAssistant::lookupSpecs()`.
