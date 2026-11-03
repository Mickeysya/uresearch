-- Six Non-Executive CGS officers: one for each team member's modules.
--
--   Officer     Email                      Team member
--   Waheda      waheda.cgs@utp.edu.my      Hani      Examiner Nomination · Conflict Detection · Re-viva
--   Akmar       akmar.cgs@utp.edu.my       Haziq     GRA · GA · Stage Gates · Allowance Eligibility
--   Norshahira  norshahira.cgs@utp.edu.my  Norhanis  Travel · Publication · Claims
--   Amirul      amirul.cgs@utp.edu.my      Chloe     Workstation · Study Candidacy
--   Zulkifly    zulkifly.cgs@utp.edu.my    Nureen    Attendance · GA Extension · Supervision · Certification
--   Syahmi      syahmi.cgs@utp.edu.my      Jason     Hardbound · Appeal Hardbound · Appointment Letters
--
-- No ids are given: the six are inserted together, so they take the next
-- six in a row (15-20 on a database with the standard seed), with no gap.
--
-- Development accounts. The password for all six is "password" (bcrypt
-- below), the same as every other test account in README.md.
--
-- Load into the Docker database:
--   docker exec -i uresearch-mysql sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' < database/sql/cgs_officers.sql
--
-- Safe to run more than once: an existing address is updated, not duplicated.
--
-- Core scopes queues by role, so on its own an account here sees every
-- Non-Executive CGS queue. A module that should go to its own officer only
-- says so itself -- Norhanis's does, in app/Modules/Norhanis/Config/cgs.php.

INSERT INTO users (name, email, password, role, department, created_at, updated_at) VALUES
    ('Waheda',     'waheda.cgs@utp.edu.my',     '$2y$12$y50l/yrPnbWVkzjkoyH8X.DMWijPPVn1quNFZGDVbEgKxOWgSaFr6', 'non_exec_cgs', 'CGS', NOW(), NOW()),
    ('Akmar',      'akmar.cgs@utp.edu.my',      '$2y$12$y50l/yrPnbWVkzjkoyH8X.DMWijPPVn1quNFZGDVbEgKxOWgSaFr6', 'non_exec_cgs', 'CGS', NOW(), NOW()),
    ('Norshahira', 'norshahira.cgs@utp.edu.my', '$2y$12$y50l/yrPnbWVkzjkoyH8X.DMWijPPVn1quNFZGDVbEgKxOWgSaFr6', 'non_exec_cgs', 'CGS', NOW(), NOW()),
    ('Amirul',     'amirul.cgs@utp.edu.my',     '$2y$12$y50l/yrPnbWVkzjkoyH8X.DMWijPPVn1quNFZGDVbEgKxOWgSaFr6', 'non_exec_cgs', 'CGS', NOW(), NOW()),
    ('Zulkifly',   'zulkifly.cgs@utp.edu.my',   '$2y$12$y50l/yrPnbWVkzjkoyH8X.DMWijPPVn1quNFZGDVbEgKxOWgSaFr6', 'non_exec_cgs', 'CGS', NOW(), NOW()),
    ('Syahmi',     'syahmi.cgs@utp.edu.my',     '$2y$12$y50l/yrPnbWVkzjkoyH8X.DMWijPPVn1quNFZGDVbEgKxOWgSaFr6', 'non_exec_cgs', 'CGS', NOW(), NOW())
AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    role = new.role,
    department = new.department,
    updated_at = NOW();

-- MySQL uses up an id even for a row it only updates, so a rerun would leave
-- a gap before the next user. Asking for 1 sets the counter back to the
-- highest id + 1.
ALTER TABLE users AUTO_INCREMENT = 1;

SELECT id, name, email, role FROM users WHERE email LIKE '%.cgs@utp.edu.my' ORDER BY id;
