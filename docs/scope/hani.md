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

### 3. Key Technical Contributions
* **Complex Query Logic:** Utilizing Laravel/PHP Eloquent (or raw MySQL queries) to calculate the 90-day gap dates dynamically and check for overlapping nominations across different faculty databases.
* **Automated Letter Generation:** (Initially in scope, later refined) Generating re-appointment letters and evaluation report PDFs via `Dompdf` to send to external panel examiners via `PHPMailer`.
* **UI/UX Design:** Designed the CGS Team Dashboard (showing aggregate examiner availability and re-viva progress) and the Admin Dashboard.

### 4. Scope Evolution (Crucial Pivot)
In the Proposal Defense, Nur Hani pitched a broad scope including Hardbound Submission, Appointment Letters, and AI matching. After physical workshops with the Academic Executive and CGS staff (Puan Waheeda), she realized Hardbound and basic Appointment Letters were better suited for teammates. She pivoted to focus strictly on **Constraint-Based Examiner Nomination** and **Re-viva Monitoring**, aligning her tech stack perfectly with the team's unified PHP/MySQL architecture.