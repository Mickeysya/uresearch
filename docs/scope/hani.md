# Nur Hani Sofia Binti Mohd Azam (22001418)
## Job Scope & Module Breakdown

**Project Title:** *Development of AI-Driven Thesis Examination and Submission Monitoring Module for UResearch 2.0 CGS Dashboard*

### 1. Core Focus
Nur Hani’s scope tackles the highly complex, rule-heavy processes of examiner allocation and post-viva re-examinations. *(Note: While the initial proposal pitched "AI-driven matching", deep-dive consultations with the Academic Executive refined this into a robust **constraint-based rule engine**).*

### 2. Assigned Modules (2 Main Modules + 1 Shared Component)

#### Module 1: Examiner Nomination & Matching (Shared Component)
* **Function:** Manages the pool of internal/external examiners and enforces strict eligibility rules.
* **Examiner State Machine:** An examiner can occupy one of four states:
  1. **Assigned:** Currently tied to an active case.
  2. **On Gap:** Serving the mandatory **90-day (3-month) cooling-off period** after an evaluation. The system calculates the exact date they become eligible again.
  3. **Available:** No active assignment, gap period elapsed.
  4. **Unavailable:** Manually flagged (e.g., retired, resigned, deceased).
* **Rule:** Both Main and Backup examiners nominated by a supervisor must pass this eligibility check at the exact time of nomination.

#### Module 2: Conflict Detection Logic (Two-Touchpoint System)
* **Function:** Prevents double-booking and cross-department conflicts.
* **Touchpoint 1 (AE Intake Stage):** When a supervisor nominates an examiner, the system checks individual eligibility (Is the examiner Assigned, On Gap, or Unavailable?). If ineligible, it flags immediately.
* **Touchpoint 2 (CGS Management Compilation Stage):** Departments submit nominations independently. When CGS Management merges department lists into a faculty-level (FOE/FSMC) list, the system checks for **cross-department duplicate nominations** (e.g., two faculties nominating the same examiner). 
* **Resolution:** Instead of auto-rejecting, the system flags the conflict and surfaces current pool availability so the Dean/CGS Management can manually decide on a substitution.

#### Module 3: Re-examination (Re-viva) Monitoring
* **Function:** Digitizes a previously informal MS Teams process for students who fail their initial viva and must re-defend.
* **Key Innovation (Formal Timestamping):** The system logs a formal submission timestamp when the student uploads the re-corrected thesis. This is critical because strict deadlines (6-month correction window, 1-year hardbound deadline) depend on this exact start date.
* **Workflow Tracking:** Uses a discrete stepper (Report sent $\rightarrow$ Under panel review $\rightarrow$ Report received $\rightarrow$ Consolidation scheduled) rather than a fake progress bar.
* **Outcome Mapping:** Tracks the 5-level re-viva outcome scale:
  * Levels 1-3: Pass with varying degrees of correction tracking.
  * Level 4: Loop back into a further re-viva cycle.
  * Level 5: Dismissal (Terminal state).

### 2b. The confirmed end-to-end flow (2026-09-22)

CGS walked the real process through and it is longer than the proposal
describes. This section is the authority where it differs from §2 above; §2 is
kept as written, because it is the scope the proposal was defended on.

1. **The supervisor names four seats**, not two: internal main and backup,
   external main and backup. The internal pair must come from **the
   candidate's own department** — that is what makes them internal — and the
   system refuses anyone else.
2. **The Academic Executive** sees which examiners have been assigned to which
   of their department's candidates, and clears them.
3. They can **take their department's list away as a file** (Excel or CSV) or
   **submit it to CGS**, which is the same act as approving it on.
4. Every department's Academic Executive does this independently, so CGS
   receives one list per department.
5. **CGS (Senior Executive, Puan Waheeda) merges them into one report** and
   checks it — this is Touchpoint 2, cross-department duplicates, and it is
   flagged inline on the compiled screen as well as on
   `/examiner-nomination/conflicts`.
6. The compiled report goes to **the Senior Director**, on screen or as a
   file.
7. **The Senior Director rechecks it.** A problem goes **back to the Academic
   Executive of that department** to choose again, and CGS is told; the list
   then replays forward. No problem, and it goes to the Dean.
8. **The Dean of PGR** approves, or sends it back the same way. Approved, it
   returns to CGS.
9. **CGS holds the finalised report** — the final examiner list.
10. It is released to **the Non-Executive (M Syahmi Ifwat M Jafri)**, who reads
    it on screen or takes the Excel or the CSV.

Built as `ExaminerNominationWorkflow`'s six stages, with
`WorkflowEngine::returnTo()` for steps 7 and 8 — a rejection would end the
candidate's nomination outright and split its audit trail, which is not what
"send it back" means here. The loop is genuinely a loop: a list can go round
as many times as it takes, on one application.

**Staffing note:** Puan Waheeda is Senior Executive CGS and M Syahmi Ifwat M
Jafri is Non-Executive CGS. The seeder had these the other way round until
2026-09-22.

### 3. Key Technical Contributions
* **Complex Query Logic:** Utilizing Laravel/PHP Eloquent (or raw MySQL queries) to calculate the 90-day gap dates dynamically and check for overlapping nominations across different faculty databases.
* **Automated Letter Generation:** (Initially in scope, later refined) Generating re-appointment letters and evaluation report PDFs via `Dompdf` to send to external panel examiners via `PHPMailer`.
* **UI/UX Design:** Designed the CGS Team Dashboard (showing aggregate examiner availability and re-viva progress) and the Admin Dashboard.

### 4. Scope Evolution (Crucial Pivot)
In the Proposal Defense, Nur Hani pitched a broad scope including Hardbound Submission, Appointment Letters, and AI matching. After physical workshops with the Academic Executive and CGS staff (Puan Waheeda), she realized Hardbound and basic Appointment Letters were better suited for teammates. She pivoted to focus strictly on **Constraint-Based Examiner Nomination** and **Re-viva Monitoring**, aligning her tech stack perfectly with the team's unified PHP/MySQL architecture.