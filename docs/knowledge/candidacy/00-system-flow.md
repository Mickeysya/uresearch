# Study Candidacy: how the system works

Single source for the system design, confirmed with CGS. Every `## cand_...` section below is an answer the assistant gives to every role, word for word. Sections
without an id are background for the team and are never shown. Role-specific answers are in 10-student.md, 20-approver.md and 30-cgs.md; Handbook statements are in
01-handbook.md. A statement marked "not available yet" is not built.

## Users and roles

Students (research and coursework). Approvers: Supervisor / Programme Manager, Department Chair, Dean of Postgraduate and Research. CGS (Centre for Graduate Studies) staff, who also
verify appeals. Registry staff work outside the system.

## Student pages

My Study Candidacy (Track My Applications > My Study Candidacy, page title "My Study Candidacy"): expiry date, extension months used and left, My Appeals table, and the
"Submit an Appeal" button when allowed. New Appeal has 4 steps: 1 Department and Supervisor / Programme Manager (read-only, from the student record); 2 Research and extension (Section A Research progress, B Earlier
extensions, C Publications, D Extension requested); 3 Documents (official appeal form .xlsx, up to 4 supporting files); 4 Review and submit. Appeal number SCA-<student ID>-<NN>.

## Approval route

Student, Supervisor / Programme Manager, Department Chair, CGS Verification, Dean. Return needs at least one reason ("Other" needs a remark). A return goes to the student and, after
resubmission, back to the step that returned it; after a Dean return CGS re-checks first. A Dean rejection is final and needs a second confirmation. An appeal
that stays returned past the expiry date is closed as expired (daily 07:30).

## Approval Portal

Tabs Pending my action, Ongoing, History (CGS: Pending my action and Decided by me, plus a "View all appeals" link to Operations). Filters: Search, Department,
Months Requested, Waiting At Least, Round. Waiting-days dot: grey under 5 days, amber 5 to 9, red 10 or more. Supervisor / Programme Manager, Department Chair and Dean must have a
signature (My Signature) before approving; signatures are stamped on decisions and the memo.

## Memo

After Dean approval the memo "Memo - Appeal for Extension" is e-mailed to the student and can be downloaded from the appeal page. File name:
`<appeal no>_<Student Name>_<Student ID>_Memo - Appeal for Extension.pdf`.

## CGS pages and reminders

Study Candidacy Management: Operations, Insights, Dismissal List, Dismissal Upload. Reminders go to research students 3, 2 and 1 month before the expiry date at 08:00
(command candidacy:send-reminders) and stop or pause as described in the reminders answer. Not available yet: Excel export of the Operations list, a reminder
log page, 3-day and 1-day urgent reminders, a CGS page to change an expiry date by hand. Softbound status comes from demo data for now. Expiry changes for
deferment, change of mode and change of programme are approved by the Registrar and updated by CGS.

## cand_approval_route

An appeal goes in this order: Student, Supervisor / Programme Manager, Department Chair, CGS Verification, Dean of Postgraduate and Research. Each step approves it (it moves on) or
returns it to the student with a reason. Only the Dean can reject, and a rejection is final.

Source: UResearch Study Candidacy design (confirmed with CGS).

## cand_status_meanings

• Draft: started, not submitted yet.
• With Supervisor / Programme Manager, With Department Chair, With CGS, With Dean: waiting for that step.
• Returned: sent back to the student with reasons.
• Approved: the Dean approved; the expiry date is recalculated and the memo is e-mailed.
• Rejected (final): the Dean rejected it.
• Closed expired: returned and not resubmitted before the candidacy expired.

Source: UResearch Study Candidacy design (confirmed with CGS).

## cand_twelve_month_limit

The maximum extension is {{cand_max_extension_months}} months in total, across all appeals together (for example 6 + 6, or 3 + 4 with 5 months left). When all
{{cand_max_extension_months}} months are used no further appeal can be submitted.

Source: UResearch Study Candidacy design (confirmed with CGS).

## cand_deadline

An appeal must be submitted on or before the expiry date. The expiry day itself is allowed; the day after is too late.

Source: UResearch Study Candidacy design (confirmed with CGS).

## cand_withdraw

An appeal cannot be withdrawn once it is submitted. A returned appeal can be corrected and resubmitted; after a Dean rejection no further appeal is possible.

Source: UResearch Study Candidacy design (confirmed with CGS).

## cand_appeal_rules

• The candidacy must be active and not expired, with no appeal already in progress.
• Submit on or before the expiry date (the expiry day is allowed).
• Maximum extension {{cand_max_extension_months}} months in total.
• One appeal at a time; no withdrawal after submission.
• Route: Student, Supervisor / Programme Manager, Department Chair, CGS Verification, Dean. Only the Dean can reject, and a rejection is final.

Source: UResearch Study Candidacy design (confirmed with CGS).

## cand_faq

• How long can an extension be? Up to {{cand_max_extension_months}} months in total.
• Can an appeal be made on the expiry day? Yes, on or before it.
• Who decides? The approvers; only the Dean can reject.
• Can an appeal be withdrawn? No.
• Are there reminders? Yes, 3, 2 and 1 month before the expiry date, at 08:00.

Source: UResearch Study Candidacy design (confirmed with CGS).

## cand_outcome_prediction

I can't predict whether an appeal will be approved. The approvers and the Dean decide from the reasons and documents in the appeal. I can explain the rules, show
the current status and say who has the appeal.

Source: UResearch Study Candidacy design (confirmed with CGS).
