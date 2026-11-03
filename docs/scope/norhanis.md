# Norhanis Erna Natasha Binti Mohd Hufzaini (22006318)
## Job Scope & Module Breakdown

**Project Title:** *Development of a Centralized Postgraduate Administrative Portal for UResearch 2.0 at the Centre for Graduate Studies (CGS)*

### 1. Core Focus
Norhanis’s scope is the "conveyor belt" of the system. She is responsible for digitizing complex, existing university approval hierarchies without altering UTP's actual administrative policies. Her core innovation is **Multi-Tier Workflow Routing** and **Real-Time Status Tracking**.

### 2. Assigned Modules (4 Modules)

#### Module 1: Travel Module
* **Function:** Routes local and international travel requests through varying approval chains.
* **Routing Logic:** 
  * Student submits $\rightarrow$ Supervisor endorses $\rightarrow$ Chair of Department endorses $\rightarrow$ Non-Executive CGS (Review).
  * **If Local Travel:** Senior Director CGS (Final Approval).
  * **If International Travel:** Dean of PGR (Final Approval).
* **Feature:** Automated email notifications update the student at every decision point.

#### Module 2: Publication Module
* **Function:** Manages conference/journal funding applications and Letters of Undertaking (LOU).
* **Routing Logic:** Routes sequentially through a strict 4-stage approval pathway:
  1. Lecturer/Supervisor (Endorsement)
  2. Chair of Department (Endorsement)
  3. Non-Executive CGS (Review)
  4. Senior Director CGS (Final Approval)
* **Cost (amended with CGS, 2026-10-30):** the student picks the *Cost Centre to be Utilized* from a list: CGS – Pay and Claims, CGS – Letter of Undertaking, Split Memo, or Others (specify). There is no fee-in-ringgit field and no separate LOU tick box. A split memo is not handled in UResearch: CGS emails the template and the supervisor completes it outside the system.
* **Documents:** every document is required. The Authorship Contribution Form was removed at CGS's request.

#### Module 3: Claims Module
* **Function:** Handles **Student Claims** (reimbursements) only. 
* **Scope Constraint:** *External Claims were explicitly excluded* after confirming with the Academic Executive that they are handled outside the student portal.
* **Routing Logic:** Routes student reimbursement receipts through: Supervisor $\rightarrow$ Chair $\rightarrow$ Non-Executive CGS $\rightarrow$ Manager CGS $\rightarrow$ Project Director (Payment Processing).

> **RPD Candidacy** (reminders, appeals, dismissals) was originally Module 4 here. It was removed from Norhanis' scope on 2026-10-30 and its code taken out of `app/Modules/Norhanis`.

### 3. Key Technical Contributions
* **Workflow Routing Engine:** Developing the PHP logic that dynamically checks the application type (e.g., Local vs. International) and routes it to the correct sequence of approvers.
* **CGS Dashboard (Chart.js):** Building the bird's-eye view dashboard for administrative staff, showing exactly how many applications are stuck at the "Chair" stage vs. the "Dean" stage, highlighting bottlenecks.
* **UI/UX Design:** Designed the initial wireframes for the Login Page, Admin Dashboard, and the specific multi-step application tracking interfaces.

### 4. Scope Evolution (Crucial Pivot)
* **Separated Travel & Publication:** In the proposal, Travel and Publication were grouped. Deep-dive meetings with CGS (Norshahirah) revealed they have distinct approval chains and documentation (like LOUs), so she separated them into dedicated modules.
* **RPD removed from scope (30 Oct 2026):** RPD Candidacy is no longer part of Norhanis' modules.
* **Travel routing agreed with CGS (Oct 2026):** local travel no longer stops at the Chair; it continues to Non-Executive CGS and the Senior Director CGS.