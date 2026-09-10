# UResearch 2.0 CGS Dashboard: Feature Specification Document
**Project Title:** Development of an Intelligent Hardbound Submission, Appeal Hardbound Submission, and Appointment Management Module  
**Student:** Yau Jia Sheng (22003299)  
**Supervisor:** Dr. Savita K Sugathan  
**Target Platform:** UResearch 2.0 (CGS Module)  
**Tech Stack:** HTML5, CSS, JavaScript, PHP, MySQL  

---

## 1. Core System-Wide Features (Shared Services)
*These foundational features apply across all three modules and must be built first.*

### 1.1 Role-Based Access Control (RBAC) & Authentication
*   **Secure Login:** Email/ID and password authentication with session management.
*   **Role Routing:** Automatic redirection to the correct dashboard upon login based on user roles:
    *   Student
    *   CGS Non-Executive
    *   CGS Senior Executive
    *   Faculty Department
    *   Faculty Academic
    *   Dean
    *   System Admin

### 1.2 Real-Time Status Tracking Engine
*   **Visual Progress Stepper:** A UI component (e.g., horizontal timeline or progress bar) showing the exact current stage of a submission/request.
*   **Stage History:** Ability to click on a status step to see when it was completed and by whom.

### 1.3 Automated Notification System
*   **In-App Alerts:** A notification bell/badge on the dashboard UI indicating pending actions.
*   **Email Dispatch:** Automated SMTP emails triggered at key milestones (e.g., "Submission Received", "Action Required", "Final Approval Granted").

### 1.4 Tamper-Evident Audit Trail
*   **Background Logging:** Automatically records `Timestamp`, `User_ID`, `Action_Taken` (View, Upload, Approve, Reject), and `Record_ID` for every interaction.
*   **Admin Viewer:** A dedicated UI for the System Admin to search and filter these logs.

### 1.5 Document Generation & Archiving Engine
*   **PDF Auto-Generation:** PHP-based service (using Dompdf/TCPDF) to inject database variables into HTML templates to generate official PDFs (Appointment Letters, Dean PFR Reports).
*   **Secure File Vault:** Centralized repository for uploaded documents (Thesis PDFs, Appeal Memos) with version control.

---

## 2. Module A: Hardbound Submission
*Workflow: Student Initiation -> CGS Non-Exec Review -> CGS Senior Exec Approval -> Auto-Acknowledgement.*

### 2.1 Student Features
*   **Submission Form:** Input fields for Thesis Title, Matric Number, Program, and Supervisor details.
*   **Document Upload:** Secure upload for the final Hardbound Thesis PDF and required clearance forms.
*   **Status Dashboard:** View the real-time tracking status of the hardbound submission.
*   **Resubmission Portal:** If returned by CGS, a form to upload corrected documents and add a response to the CGS comments.

### 2.2 CGS Non-Executive Features
*   **Task Queue:** A list of newly submitted hardbound theses awaiting initial document completeness checks.
*   **Review Interface:** View uploaded documents, read thesis details.
*   **Action Buttons:** 
    *   *Return to Student:* Requires mandatory comments/reasons.
    *   *Forward to Senior Exec:* Moves the workflow to the next stage.

### 2.3 CGS Senior Executive Features
*   **Final Review Queue:** List of submissions forwarded by the Non-Exec.
*   **Final Action Buttons:**
    *   *Approve:* Triggers completion status and auto-emails the student.
    *   *Reject:* Terminates the current workflow (can trigger the Appeal module).

---

## 3. Module B: Appeal Hardbound Submission
*Workflow: Student Appeal Filing -> CGS Non-Exec (Dean PFR) -> CGS Senior Exec (Deliberation & Ruling) -> Auto-Email Outcome.*

### 3.1 Student Features
*   **Appeal Initiation:** Button to "File Appeal" (only visible if a Hardbound Submission was rejected/returned).
*   **Appeal Memo Upload:** Form to upload the formal Appeal Memo (PDF) and provide written justification.
*   **Outcome Tracking:** View the status of the appeal and download the final ruling once decided.

### 3.2 CGS Non-Executive Features
*   **Appeal Processing Queue:** List of filed appeals.
*   **Dean PFR Generation:** A tool to compile the student's details, appeal memo, and original submission data to auto-generate the **Dean PFR (Postgraduate & Faculty Research) Approval Report** (PDF).
*   **Forwarding:** Route the appeal and the generated Dean PFR to the Senior Executive.

### 3.3 CGS Senior Executive Features
*   **Deliberation Interface:** View the Appeal Memo, original submission, and Dean PFR report.
*   **Ruling Input:** Form to input the "Deliberation Outcome" and issue a formal "Ruling" (e.g., Appeal Accepted / Appeal Rejected).
*   **Auto-Dispatch:** System automatically emails the final ruling to the student and updates the central tracking status.

---

## 4. Module C: Appointment Letter & Report Management
*Workflow: Faculty Dept Nomination -> Faculty Academic Endorsement -> Dean Approval -> Auto-Dispatch Letter -> Archiving.*

### 4.1 Faculty Department Features
*   **Examiner Nomination Form:** Input details for nominated examiners (Name, Institution, Email, Expertise) linked to a specific student.
*   **Submission:** Route the nomination to the Faculty Academic.

### 4.2 Faculty Academic Features
*   **Endorsement Queue:** View nominations from the Faculty Department.
*   **Action Buttons:** *Endorse* (routes to Dean) or *Reject* (routes back to Faculty Dept with comments).

### 4.3 Dean Features
*   **Final Approval Queue:** View endorsed nominations.
*   **Action Buttons:** *Approve* (triggers letter generation) or *Reject*.

### 4.4 Automated System Actions (Triggered by Dean Approval)
*   **Appointment Letter Generation:** System pulls Examiner and Student details, injects them into the **Appointment Letter Template (Appendix B)**, and generates a PDF.
*   **Auto-Email Dispatch:** Emails the generated PDF directly to the appointed examiner's email address.
*   **Progress Report Archiving:** Stores the generated letter and any associated progress reports in the secure Document Vault with version history.

---

## 5. User Interfaces (Dashboards)

### 5.1 Login Page
*   Clean UI with role-based entry points or a unified login that routes based on credentials.

### 5.2 Student Dashboard
*   **Action Cards:** Quick buttons to "Submit Hardbound" or "File Appeal".
*   **My Submissions Table:** List of all active and past submissions with visual status badges (e.g., Pending, Approved, Returned).
*   **Document Vault:** Downloadable receipts, generated letters, and submitted files.

### 5.3 CGS Dashboard (Non-Exec & Senior Exec)
*   **Pending Actions Queue:** Filterable list of tasks requiring their specific attention (Hardbound reviews, Appeal processing).
*   **Lifecycle Monitor:** Master table showing all postgraduate students and their current completion stage across all modules.
*   **Tools:** Quick access to generate Dean PFR reports and issue Appeal rulings.

### 5.4 Faculty & Dean Dashboard
*   **Approval Queue:** Specifically tailored for Nominations (Faculty Academic) and Final Approvals (Dean).
*   **History:** View past appointments and endorsed letters.

### 5.5 Admin Dashboard
*   **User Management:** Create, edit, and assign roles to users.
*   **Audit Log Viewer:** Searchable data table of all system actions.
*   **System Analytics:** High-level stats (Total submissions, average approval times, pending bottlenecks).

---

## 6. Database Architecture (MySQL)

### Key Tables Required:
| Table Name | Purpose | Key Fields |
| :--- | :--- | :--- |
| `users` | System users & roles | `id`, `name`, `email`, `password_hash`, `role_id` |
| `roles` | RBAC definitions | `id`, `role_name` (Student, CGS_NonExec, Dean, etc.) |
| `hardbound_submissions` | Module A data | `id`, `student_id`, `thesis_title`, `file_path`, `status`, `created_at` |
| `appeals` | Module B data | `id`, `submission_id`, `appeal_memo_path`, `dean_pfr_path`, `ruling`, `status` |
| `appointments` | Module C data | `id`, `student_id`, `examiner_name`, `examiner_email`, `status` |
| `audit_logs` | System tracking | `id`, `user_id`, `action`, `target_module`, `target_id`, `timestamp` |
| `notifications` | In-app alerts | `id`, `user_id`, `message`, `is_read`, `created_at` |

---

## 7. Development Roadmap (Iterative Waterfall for FYP 2)

### Sprint 1: Foundation & Core Services (Weeks 1-3)
*   Setup MySQL Database and GitHub repository.
*   Implement Authentication, RBAC, and Login routing.
*   Build the Audit Trail logging service.
*   Create base UI layouts (Header, Sidebar, Footer) for all dashboards.

### Sprint 2: Module A - Hardbound Submission (Weeks 4-6)
*   Build Student submission form and file upload logic.
*   Build CGS Non-Exec review queue and routing logic.
*   Build CGS Senior Exec approval queue.
*   Integrate PHPMailer for basic email notifications.

### Sprint 3: Module B - Appeal Hardbound (Weeks 7-9)
*   Build Student Appeal filing interface.
*   Implement Dean PFR PDF generation using Dompdf.
*   Build Senior Exec deliberation and ruling interface.
*   Link Appeal module to Hardbound module (trigger logic).

### Sprint 4: Module C - Appointment Letters (Weeks 10-12)
*   Build Faculty Dept nomination and Faculty Academic endorsement flows.
*   Build Dean approval interface.
*   Implement Appointment Letter HTML-to-PDF generation (Appendix B template).
*   Automate email dispatch of the PDF to external examiners.

### Sprint 5: Testing, UAT & Refinement (Weeks 13-14)
*   Conduct Functional Testing for each module.
*   Conduct Integration Testing (ensuring shared services work across modules).
*   Perform User Acceptance Testing (UAT) with CGS stakeholders.
*   Fix bugs and refine UI/UX based on feedback.