# Abdul Haziq bin Abdul Farouk (22007428)
## Job Scope & Module Breakdown

**Project Title:** *Graduate Research Assistant (GRA) & Graduate Assistant (GA) Student Lifecycle Management System for UResearch 2.0*

*Summarised from `docs/scope/haziq/FYP_Interim_Report_AbdulHaziq_22007428_sign.pdf` (FYP I interim report, 61 pages).*

### 1. Core Focus
Haziq’s scope is the **assistantship lifetime** — everything from a student applying for a GRA or GA post through to whether their allowance is paid this month. Where the other modules each own one application type, this one owns a *population*: the system tracks every active GRA/GA student continuously, not just while a form is in flight.

The distinctive piece is **Stage Gate monitoring**. An approved assistantship is not a finished application; it is a schedule of research milestones with deadlines, and missing one has direct financial consequences — suspended or terminated allowance. That turns the module from a workflow into a monitor.

### 2. Assigned Modules (3 Modules)

#### Module 1: GRA Application + Stage Gate Monitoring
* **Function:** The full GRA application lifecycle, from submission to an approved record with a live milestone schedule.
* **Routing:** Student → **Admin / GRS Exec** (document verification) → **Supervisor** → **Senior Director**.
* **Two-status supervisor stage:** the Supervisor first marks the application **"In Process"** while confirming appointment details such as service duration and allowance, then **"Settled"** once finalised, which releases it for final approval. Identified during requirement gathering as a real source of delay that a single approve/reject flag could not represent.
* **Reminder Engine:** where a required document is missing or fails verification, the system notifies the student automatically instead of an administrator noticing and emailing them.
* **On approval the system automatically** creates the GRA record, generates the Stage Gate schedule, and produces the **Offer Letter and Admission Letter**.
* **Extensions** reuse the same approval workflow before the service period is extended.

**Stage Gate Monitoring** — the heart of the module, and asymmetric by design:

| | **Stage 1 — RPD (recoverable)** | **Stages 2–4, PhD only (not recoverable)** |
|---|---|---|
| Deadline passes | Milestone not completed | Milestone not completed |
| Grace period | Monthly reminder — *“finish or lose allowance”* | Monthly reminder — *“finish or get terminated”* |
| Grace period ends | Allowance **suspended** (one-time notice) | Student **auto-terminated** (one-time notice) |
| Completed later | Allowance **back-paid** from the month after grace ended | No recovery — termination is final |

#### Module 2: GA Application
* **Function:** The Graduate Assistant application, which differs from GRA by including an interview.
* **Routing:** Student → **Admin / CGS** (eligibility check; ineligible applications are rejected and the student notified, ending the process) → **Research Centre** (conducts the interview, submits Pass/Fail with notes) → **Admin / CGS** (records the final decision).
* **On approval the system** auto-generates the **GA Offer Letter and Program Offer Letter**, creates the GA record, and enters the student into the same Stage Gate monitoring shared with GRA.

#### Module 3: Allowance Eligibility
* **Function:** A **reporting module, not a workflow** — nobody submits anything to it. It derives each student’s current allowance eligibility from data already captured by Modules 1 and 2.
* **Daily scheduled job:** evaluates every active GRA/GA record’s stage gate status, applies the RPD suspend/recover or later-stage terminate logic, updates `allowance_payment_log` for the month as paid or not paid with the amount, and rebuilds a consolidated **Allowance Eligibility List** filterable by status and level.
* **Visible to** Admin, GRS Executive, Supervisor and Senior Director, each according to their access.
* **Design intent:** because everything needed is already recorded upstream, there is no separate allowance application for anyone to file or for staff to chase.

### 3. Key Technical Contributions
* **Stage Gate engine** — the recoverable/non-recoverable logic above, including grace-period reminders, allowance suspension, back-payment on late completion, and automatic termination.
* **Daily allowance evaluation job** feeding both the eligibility list and the analytics dashboards.
* **Automated letter generation** — Offer, Admission and Program Offer Letters produced on approval.
* **Structured application form** covering Sections B–F (Project Details, GRA Details, Academic Qualification, Working Experience, Publications) with validation on input, plus document metadata storage (file type, upload date, version, submission status) linked to each application.
* **Role-based dashboards** for five core roles — Student, Admin, GRS Exec, Supervisor, Senior Director — plus a Research Centre view.
* **Visual analytics:** application status funnel, document completeness, stage gate progress, **delay-by-actor analysis**, and allowance eligibility.
* **AI status chatbot:** a conversational FAQ built on an LLM API that, rather than serving pre-written answers, **queries the student’s live records** (applications, approval status, documents) and sends them to the model with the question, so answers reflect current state.

### 4. Scope Evolution
* **Framed as Business Process Reengineering**, with the literature review drawing on BPR in higher-education administration to justify redesigning rather than merely digitising the existing process — the two-status supervisor stage is a direct product of that framing.
* **AI narrowed to live-data retrieval.** The chatbot is explicitly *not* a static knowledge base; the report singles this out as the gap it addresses in existing work.
* **Bounded deliberately:** the report states the module is not intended to replace UResearch 2.0’s broader grant-management responsibilities, only to own the GRA/GA student lifetime.
* **Methodology:** Interactive (Iterative) Waterfall.

### 5. Integration Notes
*Added by the team — not from the interim report.*

* **Direct collision with Nureen’s built modules — resolve before building.** `app/Modules/Nureen` already ships **GA Extension & VISA** (`ga_extension`, Supervisor → CGS Staff → Senior Director CGS) and the **GA/GRA Certification Letter** (`ga_certification`, with Dompdf generation on final approval). Haziq’s Module 1 includes GRA Extension through his own chain, and both his modules generate letters on approval. These are the same real-world processes described from two directions. Agree ownership before either side writes more code.
* **Four roles in his chains do not exist in `Support\Role`:** `GRS Exec`, `Research Centre`, and his `Admin`/`CGS` split. The portal has `admin`, `non_exec_cgs`, `senior_exec_cgs`, `manager_cgs`, `senior_director_cgs`. Map his actors onto existing roles where they match and add only what genuinely has no equivalent — adding a role is a one-line change in `Support\Role`, by design.
* **Stage Gate monitoring does not fit `WorkflowEngine`.** The engine models a linear chain of approvals that terminates; a stage gate is a recurring deadline check with suspend, recover and terminate outcomes against an approved record. This needs its own tables and a scheduled command, in the same shape as Nureen’s `supervision:remind-stalled` and the RPD reminders Norhanis has planned — not a `WorkflowModule` chain.
* **Allowance back-payment is the subtlest rule in the whole portal.** “Suspended, then back-paid from the month after grace ended once RPD completes” means the allowance log must be *restated retroactively*, not just appended to. Worth modelling explicitly before writing the daily job.
* No `module_type` has been claimed in `docs/module-keys.md` yet. Likely candidates: `gra_application`, `ga_application`.
