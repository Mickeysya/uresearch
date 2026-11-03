# Nureen Nellysha Binti Norazizi (22006973)
## Job Scope & Module Breakdown

**Project Title:** *Design and Development of an Intelligent Postgraduate Administration and Workflow Automation System with Predictive Analytics for UResearch 2.0*

### 1. Core Focus
Nureen’s scope focuses on shifting CGS from *reactive* administrative tracking to *proactive* intervention, alongside automating standard document workflows and compliance checks.

### 2. Assigned Modules (4 Modules)

#### Module 1: Attendance Record Module
* **Function:** Automates the calculation of student attendance percentages by pulling data from UTrace, eliminating manual Excel verification.
* **Key Innovation (Predictive Analytics):** Implements an **Early Warning System** (based on the Gradual At-Risk / GAR model concept). It predicts which students are on track to fall below the mandatory **80% minimum attendance threshold** *before* it happens.
* **Workflow:** 
  * System calculates attendance and flags at-risk students.
  * Sends personalized, proactive alerts to the student and supervisor.
  * Adds the student to a CGS "At-Risk" dashboard list.
  * Allows students to file an attendance appeal directly through the portal, which routes to CGS staff for review.

#### Module 2: GA (Graduate Assistant) Extension Module
* **Function:** Manages applications for extending Graduate Assistantships.
* **Workflow (realigned with CGS, 2026-10-31 — see §5):**
  * System performs **automated document completeness validation** prior to submission (four required document types, including an identity document), preventing incomplete forms from reaching CGS.
  * Routes the validated application through CGS's confirmed UResearch process by default: Supervisor (endorses) $\rightarrow$ Non-Executive CGS (final decision). The Senior Director CGS no longer has a decision stage here; they are emailed every outcome for information only.
  * A config switch (`nureen.ga_extension.final_approver`) can restore the original three-step chain — Supervisor $\rightarrow$ CGS Staff (intermediate review) $\rightarrow$ Senior Director CGS (final) — without a code change.
  * Triggers automated email notifications to the student at every step, with the endorsing supervisor copied on the final decision.

#### Module 3: Supervision Module
* **Function:** Manages Main and Co-Supervisor appointment requests.
* **Workflow (realigned with CGS, 2026-10-31 — see §5):**
  * Student submits a request naming the supervisor, the appointment role (Main or Co-Supervisor) and type (New or Existing), with required documentation.
  * System checks completeness and forwards to the named Supervisor for endorsement.
  * Upon Supervisor endorsement, it goes to the **Chair of Department** (of the student's own department) for approval — a department-scoped role, not a university-wide one.
  * By default the Chair's approval is final: the system immediately issues the appointment letter (PDF, with a unique reference number), sets the student's supervisor record for a Main appointment only, and never touches it for a Co-Supervisor appointment. CGS keeps control AFTER issuance instead of before it — every issued letter is visible to Non-Executive CGS, who can revoke it with a mandatory reason.
  * A config switch (`nureen.supervision.cgs_stage`) can restore a CGS eligibility-review stage after the Chair, with the letter generated only on CGS's own approval.
* **Key Innovation:** If rejected, the student receives specific feedback and the named supervisor is copied. If the approval process stalls at either the Supervisor's or the Chair's desk, the system **automatically sends reminder escalations** to the pending approver, looping in CGS Staff after repeated reminders.

#### Module 4: GA/GRA Certification Letter Module
* **Function:** Digitizes the endorsement workflow for issuing GA/GRA completion certificates.
* **Workflow (realigned with CGS, 2026-10-31 — see §5):**
  * Student picks GA or GRA and the form shows only the fields relevant to that type — course details for a GA, research project details for a GRA — each independently configurable without a code change.
  * Routes first to the **student's own named supervisor** for review, then to CGS Staff, then to the Senior Director CGS for final digital endorsement. Submission is blocked up front with a clear message if the student has no supervisor on record, rather than creating an application nobody can review.
  * CGS staff may optionally see an automatic appointment-record check (off by default — not part of CGS's confirmed process, config-gated).
* **Key Innovation (Automated PDF Generation):** Upon final approval, the system integrates **Dompdf** to automatically generate, format, and dispatch the official PDF certification letter, which the student can instantly download.

### 3. Key Technical Contributions
* **State Machine Diagram:** Designed the universal application lifecycle state machine (Submitted $\rightarrow$ Under Review $\rightarrow$ Approved/Rejected) used across the GA Extension, Supervision, and Certification modules.
* **Predictive Engine Logic:** Tasked with developing the PHP/MySQL logic to query historical attendance data and calculate risk probabilities for the dashboard.
* **UI/UX Design:** Designed the Student Dashboard (showing predicted attendance and active applications) and the CGS Staff Dashboard (showing document verification queues and at-risk alerts).

### 4. Scope Evolution
During the proposal defense, the scope included broad AI concepts. Through deep-dive interviews with CGS staff (En Zulkifly), the scope was refined to focus on **rule-based predictive analytics** and **automated document validation**, ensuring the features were practically implementable within the FYP2 timeline using PHP and MySQL.

### 5. CGS Process Realignment (2026-10-31)

Validated with CGS through two sources — written answers from CGS staff (En
Zul) on the CURRENT process, and CGS's official UResearch process workflow
diagrams with Client Charter days per stage, describing the NEW process.
Where the two agreed, that confirmed the requirement; where they differed,
the decision below favours the new UResearch process, implemented as a
config switch so it can be reverted without a code change. Existing
in-flight applications keep running the chain they were originally
submitted under — see each module's own `*_snapshot` field.

| Decision | New default | Config switch | Old behaviour, still available |
|---|---|---|---|
| GA Extension's final approver | Non-Executive CGS decides; Senior Director informed only | `nureen.ga_extension.final_approver` = `'cgs'` \| `'senior_director'` | `'senior_director'`: Supervisor → CGS Staff → Senior Director |
| Supervision's CGS step | Automated — the Chair's approval issues the letter immediately; CGS controls it after issuance | `nureen.supervision.cgs_stage` = `'automatic'` \| `'manual'` | `'manual'`: an explicit CGS eligibility-review stage after the Chair, letter generated on CGS approval |
| Certification's GA/GRA fields | Type-specific fields (course vs. research project), each independently configurable | `nureen.ga_certification.fields` (per type) | N/A — this replaces one generic "purpose" field with a type-aware list; `purpose` itself is kept as an optional extra field |

Official Client Charter working days per module and stage are recorded in
`nureen.charter_days` (config); not yet consumed by reminders or analytics,
which is a later task.