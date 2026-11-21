# UResearch 2.0 — Hani demo video storyboard

Target ≈ **3:25** planned, leaving ~35 s headroom for typing and transitions under 4:00 (cap 4:30). 1920×1080, 30 fps, H.264, no audio, burned-in captions.
All data is fictional (HaniDemoSeeder + ExaminerNominationUatSeeder). Logins and
page loads are cut; "⟶ cut" marks a user switch.

**Before recording (off camera):** `./sync.sh --fresh --yes`, then the UAT seeder,
then clear Mailpit and run `re-viva:monitor` (`./reseed.sh` does all of it).
6f shows the panel's letter email rather than a reminder: on a Saturday the seeded
Hazwani round sits at 11 working days, one short of the 10-day reminder.
3a shows the real Internal Main `<select>` as an open list for ~3 s, because a
native dropdown is not painted in a headless screencast.

| # | Segment | User / role | Page | Action (highlight = amber box, held 2–3 s) | Caption | Est. s |
|---|---|---|---|---|---|---|
| 0 | Title card | — | — | Navy card, name/matric, kicker, title, subtitle, 3 chips | — | 4 |
| 1a | Viva notice | `aiman.roslan@example.com` (S01, MSc student) | `/viva/eligibility` step 1 | Tick the 5 Handbook items, Continue | The student completes the Handbook eligibility checklist. | 8 |
| 1b | | same | step 2 Thesis | Title, abstract, keywords typed (fast-typed, short text pre-filled for the long abstract) | The title, abstract and keywords also feed the examiner suggestions later. | 7 |
| 1c | | same | step 3 Date & proof | Enter a softbound date 1 month away → highlight the error | The softbound date must be at least 3 months away, or the notice is refused. | 6 |
| 1d | | same | step 3 → 4 Review → Submit | Fix the date, attach proof PDF, submit; highlight the stepper | Once the date is fixed, the notice is filed and the progress tracker starts. | 5 |
| 1e | | ⟶ cut `nurul.aziz@example.com` (supervisor) | `/viva/queue` | Highlight Endorse / Return + Comment; choose Endorse, submit | The supervisor endorses it first, or returns it with a comment. | 7 |
| 1f | | ⟶ cut `ae@utp.edu.my` (AE, Computing) | `/viva/queue` | Mark each of the 5 items Verified, highlight the "every item" hint, Verified, submit | The AE verifies every item. The notice only passes when all are met. | 9 |
| 2a | Softbound checks | `ae@utp.edu.my` | `/viva/softbound/queue` → #185 Nur Farah | Open Form #019 card; highlight System checks table (similarity read from report, single sources >5%, title, word count) | For another student, Nur Farah, the system reads the Turnitin report itself: similarity, single sources above 5%, title and word count. | 9 |
| 2b | | same | same | Highlight the 2 Warning rows | Anything that doesn't match is a warning for the AE, never a silent pass. | 6 |
| 2c | | same | same | Approve | The AE approves, and the softbound thesis is verified. | 5 |
| 3a | Examiner nomination | ⟶ cut `supervisor@utp.edu.my` (Dr. Aisyah Rahman) | `/examiner-nomination/new` | Pick Nur Farah Adilah, Continue to Internal panel; open Internal Main list, highlight greyed "Prof Dr Rahmat bin Ismail (On Gap)" | Only Available examiners can be picked. Anyone in their 90-day gap is greyed out. | 9 |
| 3b | | same | Internal panel | Highlight the top suggestion's score + "matched on" terms; click Use as main | Suggestions are ranked by TF-IDF, and each one shows why it matched. | 8 |
| 3c | | same | Internal panel | Highlight Dr Tan Mei Xuan's co-authorship flag (expand evidence); use as backup | Conflicts of interest are flagged with the evidence: here, a paper co-written with the supervisor. | 8 |
| 3d | | same | External panel | Use Prof Ong Siew Ling (main); Assoc Prof Gan Wei Jie (backup), highlight his "already nominated by another department" flag | An examiner already on another department's list is flagged too. | 7 |
| 3e | | same | Notes → Review → Submit | Quick scroll of Review, submit | The supervisor submits the four-seat panel. | 4 |
| 3f | | ⟶ cut `ae@utp.edu.my` | `/examiners` | Highlight Available 39 / On Gap 6 / Assigned 11 cards | Pool states are worked out from dates, so nobody updates a spreadsheet any more. | 6 |
| 4a | DAC + conflicts | `ae@utp.edu.my` | `/examiner-nomination/dac` | Highlight Nur Farah's nomination with its flags | The AE compiles nominations for the DAC. Nothing reaches CGS without a DAC record. | 7 |
| 4b | | same | DAC PDF (download → open in PDF viewer) | Hold on the compiled list | The compiled list is generated as a PDF for the DAC meeting. | 6 |
| 4c | | ⟶ cut `waheda.cgs@utp.edu.my` (Senior Exec CGS) | `/examiner-nomination/conflicts` | Highlight "3 examiners are named by more than one department", then the Conflict row (S03 Chemical vs S04 Civil) | At faculty level, the same examiner nominated by two departments is flagged, not rejected. | 8 |
| 4d | | same | same | Highlight "Possibly the same person" (Hassan bin Omar / Hassan Omar, PhD) | Near-identical names are caught too, so one person isn't booked twice. | 6 |
| 5a | Viva scheduling | ⟶ cut `ae@utp.edu.my` | `/viva/scheduling` | Highlight Waiting row: Nur Farah — "Examiner nomination not released" | A viva can only be set once the softbound is verified and the nomination is released. | 6 |
| 5b | | ⟶ cut `ae.mechanical@utp.edu.my` | `/viva/scheduling` | Highlight Ready row Lee Jia Hui (with divergence badge); Set Date | Lee Jia Hui is ready. Her examiners' report categories differ, so the AE sees it before setting the date. | 6 |
| 5c | | same | schedule form | Pick a Saturday, venue, chair; submit → highlight weekend warning; confirm | Weekends, public holidays and clashes are warned about, and the AE decides. | 8 |
| 5d | | Mailpit `localhost:8025` | Viva invitation email | Open it; highlight the `.ics` attachment | Setting the date sends calendar invites to the student, supervisor, examiners and chair. | 6 |
| 5e | | ⟶ cut `lee.jiahui@example.com` (student) | `/viva/eligibility` | Highlight the progress panel with the new date | Students follow their progress but never see examiner names. | 5 |
| 6a | Re-viva monitoring | ⟶ cut `waheda.cgs@utp.edu.my` | `/re-viva/cycles` | Highlight a round's resubmission window + "reports due" | Every re-viva has a formal submission time, so each deadline is calculated from it. | 6 |
| 6b | | same | same | Click "Why" next to the risk score; highlight the factor breakdown | The risk score lists its factors. It's rule-based, not a prediction. | 7 |
| 6c | | same | Correction letter PDF (staff-only file) | Open in PDF viewer, hold | Correction letters and re-evaluation forms are generated automatically. | 6 |
| 6d | | ⟶ cut `dean@utp.edu.my` (Dean of PGR) | `/re-viva/queue` | Elina Rv-Dean: highlight the pack, Approve | The Dean approves the pack… | 5 |
| 6e | | ⟶ cut `waheda.cgs@utp.edu.my` | `/re-viva/queue` (Ready to Send) | Farid Rv-Ready: Send to panel | …and CGS sends it to the panel in one click, with signed, expiring links. | 5 |
| 6f | | Mailpit | panel letter email (from 6e) | Open it; highlight "Please return … by <date>" | The panel gets a working-day deadline: 20 for Master, 30 for PhD, with reminders before it falls due. | 6 |
| 7 | Closing card | — | — | "Three modules, one examination workflow" + 3 chips | — | 4 |
| | **Total** | | | | | **≈ 205 s (3:25)** |

Segment subtotals: title 4 · notice 42 · softbound 20 · nomination 42 · DAC/conflicts 27 · scheduling 31 · re-viva 35 · closing 4.

## As recorded (polish pass, 21 Nov 2026)

Scene 1 splits two steps into two captions each ("…the notice is filed…" /
"…and the progress tracker starts."; "The AE verifies every item." / "The notice
only passes when all are met."). Every caption is at most 5 s and starts with its
highlight; a caption whose highlight sits in the bottom band is drawn at the top.
The candidate pick (3a) and the schedule form fill (5c) happen off camera.
`captions.json` and `out/timings.txt` are regenerated on every build.

| Time (s) | Segment | Caption |
|---|---|---|
| 4.0–9.0 | 1. Viva notice | The student completes the Handbook eligibility checklist. |
| 10.1–15.1 | 1. Viva notice | The title, abstract and keywords also feed the examiner suggestions later. |
| 17.7–20.9 | 1. Viva notice | The softbound date must be at least 3 months away, or the notice is refused. |
| 20.9–25.9 | 1. Viva notice | Once the date is fixed, the notice is filed… |
| 26.1–29.1 | 1. Viva notice | …and the progress tracker starts. |
| 29.7–34.7 | 1. Viva notice | The supervisor endorses it first, or returns it with a comment. |
| 35.3–39.9 | 1. Viva notice | The AE verifies every item. |
| 40.3–44.8 | 1. Viva notice | The notice only passes when all are met. |
| 47.6–52.6 | 2. Softbound checks | For another student, Nur Farah, the system reads the Turnitin report itself: similarity, single sources above 5%, title and word count. |
| 52.6–56.6 | 2. Softbound checks | Anything that doesn't match is a warning for the AE, never a silent pass. |
| 56.6–60.6 | 2. Softbound checks | The AE approves, and the softbound thesis is verified. |
| 65.2–70.2 | 3. Examiner nomination | Only Available examiners can be picked. Anyone in their 90-day gap is greyed out. |
| 70.2–74.2 | 3. Examiner nomination | Suggestions are ranked by TF-IDF, and each one shows why it matched. |
| 75.0–80.0 | 3. Examiner nomination | Conflicts of interest are flagged with the evidence: here, a paper co-written with the supervisor. |
| 82.0–86.8 | 3. Examiner nomination | An examiner already on another department's list is flagged too. |
| 86.8–91.8 | 3. Examiner nomination | The supervisor submits the four-seat panel. |
| 92.2–96.2 | 3. Examiner nomination | Pool states are worked out from dates, so nobody updates a spreadsheet any more. |
| 98.3–103.3 | 4. DAC + conflict detection | The AE compiles nominations for the DAC. Nothing reaches CGS without a DAC record. |
| 103.3–107.8 | 4. DAC + conflict detection | The compiled list is generated as a PDF for the DAC meeting. |
| 107.8–112.8 | 4. DAC + conflict detection | At faculty level, the same examiner nominated by two departments is flagged, not rejected. |
| 114.7–118.7 | 4. DAC + conflict detection | Near-identical names are caught too, so one person isn't booked twice. |
| 121.3–125.8 | 5. Viva scheduling | A viva can only be set once the softbound is verified and the nomination is released. |
| 125.8–130.8 | 5. Viva scheduling | Lee Jia Hui is ready. Her examiners' report categories differ, so the AE sees it before setting the date. |
| 132.4–137.4 | 5. Viva scheduling | Weekends, public holidays and clashes are warned about, and the AE decides. |
| 139.9–144.5 | 5. Viva scheduling | Setting the date sends calendar invites to the student, supervisor, examiners and chair. |
| 144.5–148.5 | 5. Viva scheduling | Students follow their progress but never see examiner names. |
| 150.8–154.8 | 6. Re-viva monitoring | Every re-viva has a formal submission time, so each deadline is calculated from it. |
| 155.8–160.3 | 6. Re-viva monitoring | The risk score lists its factors. It's rule-based, not a prediction. |
| 160.3–164.8 | 6. Re-viva monitoring | Correction letters and re-evaluation forms are generated automatically. |
| 164.8–169.8 | 6. Re-viva monitoring | The Dean approves the pack… |
| 170.0–175.0 | 6. Re-viva monitoring | …and CGS sends it to the panel in one click, with signed, expiring links. |
| 175.1–180.1 | 6. Re-viva monitoring | The panel gets a working-day deadline: 20 days for Master, 30 for PhD, with reminders before it's due. |
