# UResearch 2.0: Centralized Postgraduate Administrative Portal
## Technical Specifications & Project Overview

### 1. Executive Summary
**UResearch 2.0** is a comprehensive, web-based administrative portal designed specifically for the **Centre for Graduate Studies (CGS)** at **Universiti Teknologi PETRONAS (UTP)**. Developed by a six-member Final Year Project (FYP) team under the supervision of **Dr. Savita K. Sugathan** and examined by **Dr. Helmi B Mohd Rais**, the system replaces fragmented, manual, and paper-based postgraduate administrative workflows with a unified, digital, and automated ecosystem.

### 2. Problem Statement & Background
Currently, CGS manages postgraduate affairs using disconnected platforms (UTrace, UCampus, Microsoft Forms, Excel spreadsheets, and manual email correspondence). This legacy approach has led to four critical issues:
1. **Manual Bottlenecks:** CGS staff spend excessive time manually verifying documents, calculating dates, and routing papers for physical signatures.
2. **Fragmented Data Silos:** Information is scattered across multiple systems, preventing staff from getting a holistic view of a student's administrative status.
3. **Lack of Transparency:** Students and supervisors have no visibility into application statuses, resulting in constant manual follow-ups ("any update?" emails).
4. **Reactive Management:** Issues like student attendance drops or missed Research Proposal Defence (RPD) deadlines are only addressed *after* they occur, rather than being predicted or automatically flagged in advance.

### 3. Target Users & Role-Based Access Control (RBAC)
The system features distinct, tailored dashboards for all stakeholders involved in the postgraduate lifecycle:
* **Postgraduate Students:** Submit applications, upload documents, track status, and receive automated notifications.
* **Lecturers / Supervisors:** Endorse applications, nominate examiners, and manage supervision requests.
* **Approvers (Chairs of Department / Dean of PGR / Senior Director CGS):** Review and approve high-level requests (e.g., International Travel, RPD Appeals).
* **CGS Staff (Non-Executive, Manager, Senior Director):** Verify documents, manage workflow queues, monitor dashboards, compile faculty lists, and trigger automated reminders.
* **Academic Executive (AE):** Manages department-level examiner nominations and RPD assessment scheduling (contextual reference).
* **System Administrators:** Manage user roles, system health, and global configurations.

### 4. Unified System Architecture
To ensure seamless integration across the six team members' individual modules, the team has standardized on a unified **Five-Layer Architecture**:

1. **Presentation Layer:** HTML5, CSS3, Bootstrap 5, and JavaScript. Delivers role-based dashboards (Student, Lecturer, CGS Staff, Admin).
2. **Application Layer:** PHP 8. Handles specific module business logic, controllers, and workflow routing for each team member's assigned modules.
3. **Shared Services Layer:** Centralized Authentication (PHP Sessions + Password Hashing), Role-Based Access Control (RBAC), Workflow Routing Engine, Notification Engine, Dashboarding, and Search/Record Management.
4. **Data Layer:** MySQL (Centralized relational database). Stores all user data, application records, approval histories, uploaded documents, and system logs. Managed via phpMyAdmin.
5. **External Services Layer:** SMTP Mail Server (via PHPMailer) for automated emails, Web Hosting, and future UTP Single Sign-On (SSO) integration.

### 5. Technology Stack & Tools
The team strictly standardized their development environment to prevent integration issues:
* **Frontend:** HTML5, CSS3, Bootstrap 5, JavaScript, Chart.js (for real-time administrative dashboards).
* **Backend:** PHP 8.
* **Database:** MySQL.
* **Key Libraries:** 
  * `PHPMailer` (SMTP integration for automated workflow notifications).
  * `Dompdf` (Automated PDF generation for certificates, appointment letters, and memos).
  * `Chart.js` (Data visualization for CGS admin dashboards).
* **Development & Design Tools:** XAMPP (Local server), Visual Studio Code, Git & GitHub (Version control), Figma (UI/UX wireframing), Draw.io & StarUML (System modeling, Activity/State Machine diagrams).

### 6. Methodology
The project follows the **Iterative Waterfall Methodology**. This allows the team to progress through structured phases (Planning $\rightarrow$ Requirements $\rightarrow$ Design $\rightarrow$ Development $\rightarrow$ Testing $\rightarrow$ Refinement) while retaining the flexibility to loop back to earlier phases based on continuous feedback from CGS staff (e.g., Puan Waheeda, Norshahirah, En Zulkifly) and the Academic Executive.

### 7. Evaluation Plan
The final system usability and effectiveness will be evaluated using the **System Usability Scale (SUS)**, benchmarking the UI/UX against industry standards for educational technology.