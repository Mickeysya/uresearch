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
| Suggested questions (6 per role) | `SuggestedQuestions` | `CandidacyAssistant::suggestions()` (answered by catalogue id, no model call) |
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

Counts today (candidacy domain, data lookups + guidance topics): **student 23** (7 + 16), **approver 18** (5 + 13), **CGS 38** (14 + 24); Workstation entries are unchanged.

- **Student, own record only:** expiry date and days left; extension months used and left; whether an appeal is possible now (the same `eligibilityProblems()` as the New Appeal button, with the reason); appeal number, status, step and who has it;
  why an appeal was returned; when the next reminder is sent (the reminder engine's own decision); whether the Softbound is recorded ("Demo data" is shown for the demo overlay).
- **Approver, own appeals only:** how many wait for me (the portal's "Pending my action"), the longest wait, how many waited 5 or 10 days or more, how many I approved or returned this month, how many are ongoing.
- **CGS:** appeals per step; returned, approved, rejected and closed-expired counts; the five longest waits (linked); appeals waiting 10 days or more; average waiting per step; students expiring within 1, 2 and 3 months; how many took no action;
  students at 12 of 12 months; appeals by department; reminders sent this and last month (or `window`: this_month, last_month, last_30_days); the reminder response rate; dismissal candidates, exported and not; dismissed this month.
- **Guidance (24 topics, `docs/knowledge/candidacy/*.md`):** what candidacy is and how long; when and how to appeal; documents; the approval route; statuses; after a return or rejection; the 12-month limit; the deadline; after approval; reminders; if candidacy expires;
  Softbound; deferment, change of mode and programme; My Signature; approver rules; the effect of a return; the appeal rules; FAQ; outcome prediction; and for CGS: reading Operations, Insights and the Dismissal List, export and Registry upload, the end-to-end process.

Every number comes from the same query or class the page uses (`CandidacyOperationsQuery`, `CandidacyPortalQuery`, `CandidacyStatistics`, `CandidacyDismissalList`, `CandidacyAppealService::eligibilityProblems`, `StudentStatusReader`); tests prove the assistant's numbers equal the pages'.
Lists and numbers are produced by the backend and never go to the model.

## What it never does

It explains and shows data. It **never** approves, returns, rejects, submits or withdraws an appeal, sends reminders, exports, uploads or dismisses, and never predicts a decision. Such requests are refused with a short reason and a link to the page that does it
(`cand_action_decide`, `cand_action_submit`, `cand_action_reminders`, `cand_action_export_upload`, `cand_action_dismiss`). "Will my appeal be approved?" gets: the approvers and the Dean decide; I can explain the rules and the current status.

## Sources (every guidance answer ends with a "Source:" line)

Topic text keeps **"The Handbook says"** apart from **"CGS process"**. Handbook references: sections 2.1 p.7, 2.1.1 p.8, 2.5 pp.12-13, 2.6, 2.7, 4.3 p.19, 4.4 p.20, 5.3 p.30, 7.4 p.38 of the Postgraduate Student Handbook 2025. **The Handbook PDF was not in the project folder, so the sections and pages
are those given in the brief and every source line says "(Handbook; verify page)"; verify them against the PDF before showing the assistant to CGS.** No form number, deadline or fee is invented: where the Handbook is silent the text says "CGS process" and
`docs/candidacy-cgs-confirmation-list.md` lists the item for CGS to confirm. Numbers in the text (12 months, 4 supporting documents, 10 MB, reminder months) are `{{placeholders}}` filled from the code and config.

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

## How to add a topic

1. Write `docs/knowledge/candidacy/<id>.md` in plain text (the widget shows plain text: `•` for bullets), starting with a plain sentence and ending with a `Source:` line. Use `{{cand_max_extension_months}}`-style tokens (`GuidanceLibrary::placeholderValues()`) instead of typing numbers.
2. Add the id and its roles to `CandidacyAssistant::TOPICS`, and its question, description and 5 paraphrases to `topicSpecs()`. Optionally a keyword rule in `KEYWORDS` (the fallback when the model is busy).
3. Run `php artisan test --filter="CatalogueTest|CandidacyAssistantTest"`: the catalogue test fails if an entry has no topic file or the reverse.
To add a data answer: a method in `CandidacyLookups`, its name in `methods()`, and its spec in `CandidacyAssistant::lookupSpecs()`.
