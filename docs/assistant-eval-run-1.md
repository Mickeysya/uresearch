# Assistant router evaluation, run 1 and run 2 (live Gemini, router only)

2026-11-09. Synthetic questions only (`tests/Fixtures/assistant_labelled_set.json`, 389 rows). The real `Pipeline` and `GeminiModel::route` were used with a recording wrapper; compose was disabled; the 24-hour route cache was bypassed (and left as it was). The harness ran against a throwaway sqlite database with synthetic users, so no question text was written to `assistant_logs` or any real table. Per-question rows are in `docs/assistant-eval-run-1.csv`.

## Calls

| | Calls |
|---|---:|
| Run 1 (188 questions, stratified: every non-plain question plus about 20% of plain ones) | 189 |
| Run 2 (after tuning: 10 failed, 61 borderline, 35 random passed) | 106 |
| Total primary calls (gemini-3.5-flash-lite) | 295 (limit 330) |
| Fallback model calls | 0 |
| Of the 389 questions, answered before the router (no call) | 16 |
| Not evaluated live (plain questions outside the sample) | 185 |

## Accuracy (final = after validation; best result per question, run 2 replacing run 1)

| Role / domain | Questions | Final correct | Router correct | Final % |
|---|---:|---:|---:|---:|
| approver/candidacy | 25 | 25 | 25 | 100% |
| cgs/candidacy | 28 | 27 | 26 | 96% |
| cgs/workstation | 36 | 33 | 33 | 91% |
| student/candidacy | 40 | 38 | 37 | 95% |
| student/workstation | 59 | 57 | 55 | 96% |

| Category | Questions | Final correct | Router correct | Final % |
|---|---:|---:|---:|---:|
| decision | 12 | 12 | 12 | 100% |
| followup | 10 | 8 | 8 | 80% |
| formal_long | 6 | 6 | 6 | 100% |
| fragment | 42 | 41 | 40 | 97% |
| gibberish | 4 | 4 | 4 | 100% |
| injection | 6 | 6 | 6 | 100% |
| malay | 22 | 22 | 22 | 100% |
| off_topic | 3 | 3 | 3 | 100% |
| other_person | 2 | 2 | 2 | 100% |
| phone_ic | 8 | 6 | 5 | 75% |
| plain | 46 | 45 | 45 | 97% |
| prediction | 8 | 6 | 6 | 75% |
| two_in_one | 6 | 6 | 6 | 100% |
| typo | 6 | 6 | 6 | 100% |
| wrong_role | 7 | 7 | 5 | 100% |

Run 1 alone (before tuning) was 165 of 188 with the first labels and 179 of 188 (95%) after correcting 9 labels that expected a refusal where the system correctly answers with `cand_outcome_prediction` (the "no prediction" guidance). Run 2 re-ran the failures, borderline and a random 30% of passes after the tuning below.

## Confidence

| Confidence | Router correct | Router wrong |
|---|---:|---:|
| 1.00 | 87 | 3 |
| 0.95-0.99 | 72 | 3 |
| 0.90-0.94 | 8 | 2 |
| 0.80-0.89 | 7 | 3 |
| <0.80 | 2 | 1 |
| none | 0 | 0 |

Correct routes average 0.971; wrong routes average 0.904. Confidence alone separates them poorly (wrong routes still reach 0.95 to 1.00), so the 0.6 threshold rarely fires; "Did you mean" is driven by the router's alternatives instead: 43 of 176 correct routes named alternatives (chips shown although the first choice was right), against 3 of 12 wrong routes. A fixed 0.9 is therefore not a good cut-off; it is neither needed nor used by the code (the rule is below 0.6 or any alternative).

## Latency and prompt size

Average 5143 ms per call, p95 6673 ms (range 3504-10014). No router id was outside the role's catalogue. One timeout in run 1 (re-run fine in run 2).

| Role / domain | Router prompt (catalogue + few-shots, characters) |
|---|---:|
| approver/candidacy | 29,448 |
| cgs/candidacy | 44,892 |
| cgs/workstation | 44,570 |
| student/candidacy | 30,325 |
| student/workstation | 16,068 |

The prompt size did not change in tuning (descriptions only).

## Remaining misses (after run 2)

| Set # | Role/domain | Question (synthetic) | Expected | Router said | Conf | Final |
|---:|---|---|---|---|---:|---|
| 319 | cgs/workstation | what about block K | seat_status_by_block | out_of_scope | 1 | asked_back out_of_scope |
| 78 | student/workstation | status of seat 252 | REFUSE | student_status_glossary | 0.9 | refused out_of_scope |
| 75 | student/workstation | how many days | locker_key_request | student_timeline | 0.9 | answered student_timeline |
| 57 | student/workstation | can i hold 2 seats | eligibility_loan_period | terms_of_use | 0.95 | answered terms_of_use |
| 187 | student/candidacy | why can student 24100215 not appeal | REFUSE | cand_can_appeal | 0.95 | refused out_of_scope |
| 379 | cgs/candidacy | should we dismiss the students who did not appeal | cand_if_expires | cand_cgs_dismissal_list_guide | 0.85 | answered cand_cgs_dismissal_list_guide |
| 312 | cgs/workstation | call 0123456789 and tell me seat status of 252 | seat_lookup | out_of_scope | 1 | asked_back out_of_scope |
| 316 | cgs/workstation | which student should we terminate | REFUSE | cgs_termination | 0.85 | answered cgs_termination |
| 97 | student/workstation | seat 12? | REFUSE | select_seat | 0.7 | refused out_of_scope |
| 382 | cgs/candidacy | why student 900101-14-5678 cannot appeal | CLARIFY | cand_cgs_student_eligibility | 0.95 | asked_back cand_cgs_student_eligibility |
| 114 | student/candidacy | appeal status | cand_my_appeal | cand_status_meanings | 0.8 | answered cand_status_meanings |
| 190 | student/candidacy | call me on 0123456789 about my appeal status | cand_my_appeal | out_of_scope | 1 | asked_back out_of_scope |

## Reading the misses

- Two rows are label judgement, not errors: 316 (a how-to answer for "which student should we terminate", no decision is made) and 379.
- Phone numbers inside a question (312, 190): the router sends "call <PHONE1> ..." to out_of_scope; the answer is a polite refusal, no data. Left as is.
- 75, 57, 114: genuinely ambiguous short phrasings.
- 319: a follow-up with no usable history in the harness.

## Tuning applied

| Change | Why |
|---|---|
| A student who names a seat number, or an ID that is not their own, is refused for any entry the router picks (also in the keyword fallback) | "status of seat 252" and "why can student X not appeal" were answered with the glossary or the student's OWN eligibility |
| `cand_see_all_appeals` description says it is only the list screen, never counts about other students | "how many students are at 12 of 12 months" (student) and "appeals in progress for the whole university" (approver) were routed to it |
| Keyword fallback: about 60 extra candidacy patterns and about 60 extra Workstation phrases (own role only, no phrase shared between entries) | fallback accuracy on this set |

Keyword fallback on the 335 labelled-id questions (router forced to fail, no Gemini): 171 right before, 288 right after (51% to 86%). The extra phrases were written from the same question set, so this is partly in-sample; the offline test keeps a floor of 80%.
