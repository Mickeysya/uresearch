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
* **Workflow:** 
  * System performs **automated document completeness validation** prior to submission (preventing incomplete forms from reaching CGS).
  * Routes the validated application through a strict **3-step approval chain**: Supervisor $\rightarrow$ CGS Staff (Verification) $\rightarrow$ Approver.
  * Triggers automated email notifications to the student at every step.

#### Module 3: Supervision Module
* **Function:** Manages supervisor appointment requests and assignments.
* **Workflow:** 
  * Student submits request with required documentation.
  * System checks completeness and forwards to the designated Supervisor.
  * Upon Supervisor approval, it goes to CGS staff for an eligibility review.
* **Key Innovation:** If rejected, the student receives specific feedback. If the approval process stalls, the system **automatically sends reminder escalations** to the pending approver.

#### Module 4: GA/GRA Certification Letter Module
* **Function:** Digitizes the endorsement workflow for issuing GA/GRA completion certificates.
* **Workflow:** 
  * System checks field completeness.
  * CGS staff verifies the student's current appointment status (GA vs. GRA).
  * Routes to an authorized approver for digital endorsement.
* **Key Innovation (Automated PDF Generation):** Upon final approval, the system integrates **Dompdf** to automatically generate, format, and dispatch the official PDF certification letter, which the student can instantly download.

### 3. Key Technical Contributions
* **State Machine Diagram:** Designed the universal application lifecycle state machine (Submitted $\rightarrow$ Under Review $\rightarrow$ Approved/Rejected) used across the GA Extension, Supervision, and Certification modules.
* **Predictive Engine Logic:** Tasked with developing the PHP/MySQL logic to query historical attendance data and calculate risk probabilities for the dashboard.
* **UI/UX Design:** Designed the Student Dashboard (showing predicted attendance and active applications) and the CGS Staff Dashboard (showing document verification queues and at-risk alerts).

### 4. Scope Evolution
During the proposal defense, the scope included broad AI concepts. Through deep-dive interviews with CGS staff (En Zulkifly), the scope was refined to focus on **rule-based predictive analytics** and **automated document validation**, ensuring the features were practically implementable within the FYP2 timeline using PHP and MySQL.