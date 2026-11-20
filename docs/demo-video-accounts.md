# Demo video: accounts and records (Nureen's modules)

Everything needed to record the FYP2 demo of Attendance + Appeal, GA
Extension, Supervision and GA/GRA Certification. **Every password is
`password`.**

- App: http://localhost:8000
- Mailpit (the emails each step sends): http://localhost:8025

## The records

Made by `NureenVideoDemoSeeder` on 20 Nov 2026. Each one has been submitted
and is waiting at the first stage of its live chain. Each one's reason,
justification or purpose starts **"Video demo"**.

| Module | Application | Waiting at | Chain, and who to log in as at each stage |
|---|---|---|---|
| GA Extension | **#212** | Admin | Admin `admin@` → CGS Staff `zulkifly.cgs@` → Supervisor `supervisor@` → Senior Director `director@` |
| Supervision | **#213** | Supervisor | Supervisor `supervisor@` → Chair `chair@` → Non-Executive CGS `zulkifly.cgs@` (endorses and issues the appointment letter) |
| GA/GRA Certification | **#214** | Supervisor | Supervisor `supervisor@` → CGS Staff `zulkifly.cgs@` → Senior Director `director@` (issues the PDF certification letter) |
| Attendance Appeal | **#215** | Non-Executive CGS | Non-Executive CGS `zulkifly.cgs@` (one stage) |

All addresses end in `@utp.edu.my`. The student on all four is
`student@utp.edu.my`.

**Use `zulkifly.cgs@` for every CGS step, not `cgs@`.** `config/cgs_assignment.php`
gives all four of these modules to that officer, and the engine refuses
any other Non-Executive CGS account.

Ahmad Danial also has older pending requests from earlier testing: #4
(Supervision), #184, #187 and #202 (GA Extension). They appear in the same
queues, so open the ids above.

## Every login

| Login | Name | Role | What it does in the video |
|---|---|---|---|
| `student@utp.edu.my` | Ahmad Danial (22001001) | Student, Computing, MSc Full-Time | Owns #212–#215: Track My Applications, the progress stepper, Expected Decision Dates, and downloading the issued letters |
| `admin@utp.edu.my` | System Admin | Admin | GA Extension stage 1: verifies the documents. Admin never rejects outright; a problem goes back to the student |
| `zulkifly.cgs@utp.edu.my` | Zulkifly | Non-Executive CGS | GA Extension stage 2 (fills Section J remarks; can return to Admin). Supervision stage 3 (endorses, which issues the appointment letter). Certification stage 2 (verifies). Attendance Appeal (decides). Also the CGS attendance dashboard |
| `supervisor@utp.edu.my` | Dr. Aisyah Rahman | Supervisor, Computing | GA Extension stage 3, Supervision stage 1, Certification stage 1. Also sees supervisees' attendance |
| `chair@utp.edu.my` | Dr. Lim Wei Chun | Chair, Computing | Supervision stage 2 |
| `director@utp.edu.my` | Prof. Dr. Rahimah Ahmad | Senior Director CGS | GA Extension stage 4, the final decision (can return to CGS or the Supervisor). Certification stage 3, which approves and issues the PDF |

### Attendance risk students (`AttendanceRiskDemoSeeder`)

Students whose dashboard "Attendance" card shows each case:

| Login | Name | Case | Current | Projected | Dashboard card |
|---|---|---|---|---|---|
| `hafiz-22093001@utp.edu.my` | Hafiz Danial Rosli (22093001) | Projection only | 95% | 75% | "Attend 74 of the next 100 sessions" |
| `muhammad.haziq.irfan@utp.edu.my` | Muhammad Haziq Irfan (22001011) | Current rule, reason "both" | 27.5% | 22.5% | "Justification needed", with a link to the appeal form |
| `tan.wei.jian@utp.edu.my` | Tan Wei Jian (22001012) | Steady, not flagged | 90% | 90% | "On track" |

Muhammad Haziq Irfan is the natural student to show *submitting* an appeal
live. Ahmad Danial's own attendance is 100%, so his seeded appeal #215 is
not tied to a period with missed sessions. CGS will not see the "sessions
to excuse" box on it.

## What the records contain

- **GA Extension #212**: MSc, full-time research. Current appointment ends
  31 Dec 2026; extension 1 Jan – 30 Jun 2027 (6 months). Admission 1 Jan
  2026, so the student is at Stage Gate Level 2, and the "Proof of Journal
  Article Acceptance" is attached with one accepted WoS Q2 article in
  Section C. All 8 System checks pass. *Note:* Ahmad Danial's approved GA
  application #205 ends 27 Dec 2026. Copying that date would start the
  extension on 28 Dec and fail "starts on the 1st or 16th", so the seeder
  used the next allowed end date. When the Senior Director approves, GA
  application #205's end date moves to 30 Jun 2027.
- **Supervision #213**: a Main Supervisor appointment letter for his
  existing supervisor, Dr. Aisyah Rahman. Not a change of supervisor, so
  no PGFAC memo is needed.
- **Certification #214**: GA, course TFB2023 Data Structures and
  Algorithms, May 2026. The purpose, "Video demo: supporting document for a
  scholarship application.", **is printed on the issued letter**.
- **Attendance Appeal #215**: justification letter and monthly activity
  breakdown attached.

All documents are one-page placeholder PDFs marked as demo data.

## Re-running

```bash
php artisan db:seed --class="App\Modules\Nureen\Database\Seeders\NureenVideoDemoSeeder"
php artisan db:seed --class="App\Modules\Nureen\Database\Seeders\AttendanceRiskDemoSeeder"
```

(Inside Docker: prefix with `docker exec -u sail uresearch-app`.)

- **Video demo seeder:** a record that is still pending is reused, so
  re-running just reprints the table. Once you have clicked one through to
  approved or rejected, the next run makes a fresh one with a new id for
  another take. The old one stays as history. Newly made records email
  their first approver; reused ones do not.
- **Attendance risk seeder:** replaces only those three students' weekly
  attendance rows with the same four weeks. It creates any of the three
  students that are missing.

Neither seeder wipes or deletes anything else.
