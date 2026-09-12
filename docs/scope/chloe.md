# Chloe Ching Qing En (22011629)
## Job Scope & Module Breakdown

**Project Title:** *Intelligent Academic Administration & Study Candidacy Management Module for UResearch 2.0 CGS Dashboard*

*Summarised from `docs/scope/chloe/Chloe Ching Qing En_22011629_Interim Report.pdf` (FYP I interim report, 47 pages). Supervisor: Dr. Savita K Sugathan.*

### 1. Core Focus
Chloe’s scope is the **candidature clock** — the set of CGS processes that turn on a date rather than on a document. Where Norhanis routes applications and Nureen predicts attendance, Chloe’s module watches how long a student has been enrolled and acts when a deadline approaches, passes, or is appealed. Her stated principle is that the project **digitalises existing workflows without changing a single university policy or business rule**; requirement gathering was conducted specifically to confirm that constraint.

Alongside the four workflows she adds an **AI Academic Guidance Assistant** that answers student enquiries from CGS standard operating procedures. It is explicitly advisory: *the AI makes no approval decisions of any kind.*

### 2. Assigned Modules (4 Processes + 3 System Functions)

#### Module 1: Workstation Management
* **Function:** Allocates postgraduate workstations (and their locker keys) from a live seat map.
* **As-is:** The layout lives in a PowerPoint deck, students apply through Microsoft Forms, and staff assign seats one at a time. Availability cannot be validated in real time, and keys are sometimes never collected because nothing follows up.
* **To-be:** Availability is published in the system and the student picks a seat directly. The system validates the choice, writes it to the database, and emails a confirmation — or a rejection where two students pick the same seat simultaneously. CGS keeps manual assignment for exceptional cases.

#### Module 2: Study Candidacy Reminder
* **Function:** Warns students before their study candidacy expires.
* **As-is:** Staff check each student’s status by hand, build a reminder list, and send emails monthly starting three months before expiry — three reminders in total. Students who have submitted softbound, gone inactive, or completed are struck off the list manually, and **no record is kept of which reminders were sent**.
* **To-be:** Candidacy status is checked **daily**, reminders fire automatically on the same monthly schedule, and reminder status is recorded and visible to CGS. Generation stops automatically once an appeal is submitted, softbound is approved, or the student becomes inactive or dismissed.

#### Module 3: Study Candidacy Appeal
* **Function:** Extends a student’s candidacy through a four-stage approval chain.
* **Routing:** Student → **Supervisor** → **Programme Chair** → **CGS verification** → **Dean of PGR**. Each approver may approve, reject, or **return with comment**.
* **As-is:** The appeal is an Excel template passed around by email; CGS then calculates the extension, updates the database, and sends the outcome by hand.
* **To-be:** A standardised online form, system-driven routing through the same four stages, then automatic extension calculation, database update, student notification and dashboard refresh on the Dean’s approval.
* **Business rule preserved:** a maximum appeal duration of **twelve months**, enforced by the system rather than remembered by staff.

#### Module 4: Dismiss Exceeded Study Candidacy
* **Function:** Identifies students who have run out of candidacy and prepares them for dismissal.
* **Trigger:** Either the candidacy due date passed with no appeal, or the student has already consumed the maximum twelve months of appeal.
* **To-be:** The system generates the candidate list from candidacy records for CGS to review and confirm. **Submission to the Registry stays manual and the Registry’s internal process is out of scope** — but once dismissal is confirmed, the student notification is issued automatically.

#### System Functions delivered alongside the four processes
* **Operational dashboard** — pending appeals, reminder status, and workstation occupancy. Operational information only.
* **Automated email notifications** — submissions, approvals, rejections, reminders, status updates.
* **AI Academic Guidance Assistant** — answers student questions on procedures, policies and required documents from existing CGS documentation. Guidance only, never decisions.

### 3. Key Technical Contributions
* **Five-layer architecture for the module:** Presentation (Student / Approver / CGS Staff / Administrator portals), Application (the four processes plus dashboard, notification centre and AI assistant), **Workflow** (Power Automate or n8n, with PHPMailer where needed), Integration (Microsoft Copilot Studio for AI, Outlook for mail), and Data (MySQL).
* **Daily scheduled candidacy check** — the job that scans candidacy records, decides who is due a reminder, and records what was sent.
* **Deadline arithmetic** — calculating extensions on approval and enforcing the twelve-month appeal ceiling.
* **Shared main page** — the first-level landing view shared by all six UResearch 2.0 modules, designed jointly with the other project members so the platform reads as one environment; each role lands on its own view with the same layout and theme.

### 4. Scope Evolution
* **Grounded in one interview, deliberately.** Requirements came from a semi-structured interview with **Mr. Amirul Hariz Yunus** of CGS on **23 June 2026**, plus analysis of the existing workflow documents and forms. Four findings shaped the scope: the work is heavily manual; **existing business rules must not change**; the sequential approval chain must be kept but automated; and these four processes were named by CGS as the ones costing the most administrative effort.
* **Registry excluded.** The dismissal workflow deliberately stops at "prepare the list and notify after confirmation" — the Registry’s internal dismissal process was ruled outside the boundary.
* **AI bounded to guidance.** The assistant was explicitly constrained to answering questions; it takes no part in approval or eligibility decisions.
* **Methodology:** Iterative Waterfall, on the grounds that the business processes are stable and already validated, with limited iterations for stakeholder feedback.

### 5. Integration Notes
*Added by the team — not from the interim report.*

* **Overlaps with Norhanis’s RPD module, and the two need reconciling.** `norhanis.md` Module 4 covers RPD reminders at 3/2/1 months, RPD appeals through Supervisor → Chair → Non-Exec CGS → Dean, and dismissals for exceeded candidacy routed to the Registry. Chloe’s Modules 2–4 are the same three shapes applied to **study candidacy** rather than the **Research Proposal Defence** milestone. They are genuinely different deadlines, but the reminder scheduler, the appeal chain and the dismissal list are near-identical machinery. Agree who builds which before either starts.
* **Approver chain differs by one stage.** Chloe’s appeal routes Student → Supervisor → **Programme Chair** → CGS → Dean. The portal’s `chair` role covers Chair of Department; confirm whether Programme Chair is the same person or a new role in `Support\Role`.
* **Workstation Management has no equivalent anywhere in the portal** and would need its own `module_type` plus a seat/locker table. It is also the one process here that is not an approval chain, so it will not use `WorkflowEngine` — it is closer to Attendance’s non-workflow half.
* **Power Automate, n8n, Copilot Studio and Outlook are outside the team’s agreed stack** (Laravel · MySQL · Mailpit/SMTP · Dompdf). The scheduled checks map cleanly onto Laravel’s scheduler and the notifications onto the existing mail path; the AI assistant is the piece with no current home.
* No `module_type` has been claimed in `docs/module-keys.md` yet.
