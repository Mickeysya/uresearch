# Norhanis Erna Natasha Binti Mohd Hufzaini (22006318)
## Job Scope & Module Breakdown

**Project Title:** *Development of a Centralized Postgraduate Administrative Portal for UResearch 2.0 at the Centre for Graduate Studies (CGS)*

### 1. Core Focus
Norhanis’s scope is the "conveyor belt" of the system. She is responsible for digitizing complex, existing university approval hierarchies without altering UTP's actual administrative policies. Her core innovation is **Multi-Tier Workflow Routing** and **Real-Time Status Tracking**.

### 2. Assigned Modules (4 Modules)

#### Module 1: Travel Module
* **Function:** Routes local and international travel requests through varying approval chains.
* **Routing Logic:** 
  * Student submits $\rightarrow$ Supervisor endorses $\rightarrow$ Chair of Department endorses.
  * **If Local Travel:** Stops at the Chair for final approval.
  * **If International Travel:** Automatically routes further to Non-Executive CGS (Review) $\rightarrow$ Dean of PGR (Final Approval).
* **Feature:** Automated email notifications update the student at every decision point.

#### Module 2: Publication Module
* **Function:** Manages conference/journal funding applications and Letters of Undertaking (LOU).
* **Routing Logic:** Routes sequentially through a strict 4-stage approval pathway:
  1. Lecturer/Supervisor (Endorsement)
  2. Chair of Department (Endorsement)
  3. Non-Executive CGS (Review)
  4. Senior Director CGS (Final Approval)

#### Module 3: Claims Module
* **Function:** Handles **Student Claims** (reimbursements) only. 
* **Scope Constraint:** *External Claims were explicitly excluded* after confirming with the Academic Executive that they are handled outside the student portal.
* **Routing Logic:** Routes student reimbursement receipts through: Supervisor $\rightarrow$ Chair $\rightarrow$ Non-Executive CGS $\rightarrow$ Manager CGS $\rightarrow$ Project Director (Payment Processing).

#### Module 4: RPD (Research Proposal Defence) Candidacy Module
* **Function:** A complex module handling three distinct operational paths based on time and status:
  1. **RPD Reminders:** The system automatically checks dates and triggers emails to students at **3, 2, and 1 months** prior to their strict deadlines (8 months for FT Masters/PhD, 12 months for PT Masters/PhD).
  2. **RPD Appeals (Extensions):** If a student needs more time, they submit an appeal. It routes through a multi-tier chain (Supervisor $\rightarrow$ Chair $\rightarrow$ Non-Exec CGS $\rightarrow$ Dean). Upon Dean's approval, the **system automatically recalculates the new deadline** and updates the masterlist.
  3. **Dismissals for Exceeded Candidacy:** If a student fails to appeal and passes the deadline, Non-Exec CGS initiates dismissal. Routes to Dean (Endorsement) $\rightarrow$ Faculty (Final Approval) $\rightarrow$ Registry (Sends termination email).

### 3. Key Technical Contributions
* **Workflow Routing Engine:** Developing the PHP logic that dynamically checks the application type (e.g., Local vs. International) and routes it to the correct sequence of approvers.
* **Automated Cron Jobs / Scheduled Tasks:** Implementing the logic for the RPD Reminder module to scan the database daily and trigger PHPMailer emails at the 3, 2, and 1-month marks.
* **CGS Dashboard (Chart.js):** Building the bird's-eye view dashboard for administrative staff, showing exactly how many applications are stuck at the "Chair" stage vs. the "Dean" stage, highlighting bottlenecks.
* **UI/UX Design:** Designed the initial wireframes for the Login Page, Admin Dashboard, and the specific multi-step application tracking interfaces.

### 4. Scope Evolution (Crucial Pivot)
* **Separated Travel & Publication:** In the proposal, Travel and Publication were grouped. Deep-dive meetings with CGS (Norshahirah) revealed they have distinct approval chains and documentation (like LOUs), so she separated them into dedicated modules.
* **Excluded AE Processes:** After meeting with the Academic Executive (24 July), she confirmed that department-level RPD assessment scheduling and examiner coordination are handled manually by the AE. She explicitly excluded these from her RPD module to avoid overlapping with Nur Hani's scope.