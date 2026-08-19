-- ============================================================
-- UResearch 2.0 - Database Schema
-- Modules: Travel, Publication, Claims (Student)
-- Owner: Hanis (22006318)
-- ============================================================

-- ------------------------------------------------------------
-- SHARED TABLES (used by ALL modules, not just yours)
-- Coordinate these with your DB lead before finalizing.
-- ------------------------------------------------------------

CREATE TABLE users (
    user_id         INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    email           VARCHAR(150) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    matric_no       VARCHAR(30)  NULL,          -- for students only
    programme       VARCHAR(100) NULL,          -- e.g. MSc, PhD
    contact_no      VARCHAR(30)  NULL,
    role            ENUM('student','supervisor','chair','non_exec_cgs',
                          'senior_director_cgs','manager_cgs','dean_pgr',
                          'admin') NOT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE applications (
    application_id  INT AUTO_INCREMENT PRIMARY KEY,
    student_id      INT NOT NULL,
    module_type     ENUM('travel','publication','claims_student') NOT NULL,
    status          ENUM('draft','pending','endorsed','reviewed',
                          'approved','rejected') DEFAULT 'draft',
    current_stage   VARCHAR(100) NULL,          -- e.g. 'Chair of Department'
    submitted_at    DATETIME NULL,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(user_id)
);

CREATE TABLE approval_history (
    history_id      INT AUTO_INCREMENT PRIMARY KEY,
    application_id  INT NOT NULL,
    approver_id     INT NOT NULL,
    stage           VARCHAR(100) NOT NULL,      -- e.g. 'Lecturer/Supervisor'
    decision        ENUM('endorsed','approved','rejected') NOT NULL,
    remarks         TEXT NULL,
    decided_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES applications(application_id),
    FOREIGN KEY (approver_id) REFERENCES users(user_id)
);

CREATE TABLE application_documents (
    document_id     INT AUTO_INCREMENT PRIMARY KEY,
    application_id  INT NOT NULL,
    doc_type        VARCHAR(100) NOT NULL,      -- e.g. 'Letter of Undertaking'
    file_path       VARCHAR(255) NOT NULL,
    uploaded_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES applications(application_id)
);

-- ------------------------------------------------------------
-- TRAVEL MODULE
-- ------------------------------------------------------------

CREATE TABLE travel_details (
    travel_id           INT AUTO_INCREMENT PRIMARY KEY,
    application_id       INT NOT NULL,
    travel_start_date    DATE NOT NULL,
    travel_end_date       DATE NOT NULL,
    duration_days         INT NOT NULL,
    reason_for_travel     VARCHAR(255) NOT NULL,
    destination_address   VARCHAR(255) NOT NULL,
    is_international       BOOLEAN NOT NULL DEFAULT FALSE,  -- drives workflow branch
    contact_person_name    VARCHAR(150) NULL,
    contact_person_no      VARCHAR(30) NULL,
    FOREIGN KEY (application_id) REFERENCES applications(application_id)
);

-- ------------------------------------------------------------
-- PUBLICATION MODULE
-- ------------------------------------------------------------

CREATE TABLE publication_details (
    publication_id        INT AUTO_INCREMENT PRIMARY KEY,
    application_id         INT NOT NULL,
    type_of_request         ENUM('publication_conference','publication_journal') NOT NULL,
    title_of_paper           VARCHAR(255) NOT NULL,
    conference_or_journal    VARCHAR(255) NOT NULL,
    organizer_publisher       VARCHAR(255) NULL,
    currency_type              VARCHAR(10) NULL,
    cost_to_be_utilized         DECIMAL(10,2) NULL,
    wants_letter_of_undertaking BOOLEAN DEFAULT FALSE,
    conference_start_date        DATE NULL,
    conference_end_date          DATE NULL,
    FOREIGN KEY (application_id) REFERENCES applications(application_id)
);

CREATE TABLE publication_authors (
    author_id       INT AUTO_INCREMENT PRIMARY KEY,
    publication_id  INT NOT NULL,
    author_name     VARCHAR(150) NOT NULL,
    organisation    VARCHAR(150) NULL,
    role_contribution VARCHAR(255) NULL,
    FOREIGN KEY (publication_id) REFERENCES publication_details(publication_id)
);

-- ------------------------------------------------------------
-- CLAIMS MODULE (Student only, per your scope)
-- ------------------------------------------------------------

CREATE TABLE claims_details (
    claim_id             INT AUTO_INCREMENT PRIMARY KEY,
    application_id        INT NOT NULL,
    purpose_of_claim       VARCHAR(255) NOT NULL,
    bank_account_no         VARCHAR(30) NOT NULL,
    total_claim_amount       DECIMAL(10,2) DEFAULT 0,
    less_cash_advance         DECIMAL(10,2) DEFAULT 0,
    claim_balance               DECIMAL(10,2) DEFAULT 0,
    FOREIGN KEY (application_id) REFERENCES applications(application_id)
);

CREATE TABLE claims_items (
    item_id           INT AUTO_INCREMENT PRIMARY KEY,
    claim_id          INT NOT NULL,
    item_date         DATE NOT NULL,
    travel_from       VARCHAR(150) NULL,
    travel_to         VARCHAR(150) NULL,
    flight_train_amount DECIMAL(10,2) DEFAULT 0,
    meal_allowance      DECIMAL(10,2) DEFAULT 0,
    lodging_amount       DECIMAL(10,2) DEFAULT 0,
    misc_amount            DECIMAL(10,2) DEFAULT 0,
    FOREIGN KEY (claim_id) REFERENCES claims_details(claim_id)
);

