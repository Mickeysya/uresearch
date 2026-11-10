<?php

namespace App\Modules\Haziq\Assistant;

/**
 * A written description of how this module's rules actually work --
 * sent to the model alongside the student's own live records on every
 * question, the same role Chloe's GuidanceLibrary plays for her own
 * domains. Confirmed explicitly 2026-11-06: "need to train the ai...
 * understand the logic of my modules... know every possible outcome."
 *
 * This is the right tool for that, not literal fine-tuning: with as
 * little real history as this environment has, and rules this
 * specific (a one-month payroll lag, which Stage Gate is recoverable
 * and which is not), a trained model would still have to guess at
 * anything it was never shown -- a written knowledge block the model
 * reads fresh every time is cheaper, instantly correct the moment this
 * text is corrected, and never goes stale the way a trained model's
 * weights would.
 *
 * Every rule below is something this session traced through the real
 * code and verified live, not a guess -- keep this in step with
 * AllowanceController/StageGateEngine whenever either changes, or the
 * bot starts confidently explaining a rule that no longer holds.
 */
class KnowledgeBase
{
    public static function text(): string
    {
        return <<<TEXT
            GRA APPLICATION
            A new appointment or an extension, routed Student -> Admin (verifies
            paperwork, never rejects outright -- a problem becomes a correction
            request sent back to the student, not a rejection) -> CGS Staff ->
            Supervisor -> Senior Director CGS (final approval). Approval creates the
            GRA record, generates the Stage Gate schedule below, and issues the
            Offer and Admission Letters automatically. A student can keep editing
            every section of their own application at any time it is pending, or
            approved and not terminated -- not only once something is flagged;
            editing is not locked to waiting for a correction request first.

            A NEW appointment's own service period is never typed in by the
            student -- its end date is always computed automatically as the start
            date plus 24 months for a Master's student or 42 months for a PhD
            student, matching the Stage Gate schedule's own last milestone. Only
            one 'new' GRA application may be in flight at a time (pending or
            approved) -- a second attempt is blocked while one is. A REJECTED
            application does not block a fresh one: the student may submit again
            at any time after a rejection.

            GRA EXTENSION
            Reuses the exact same four-stage chain as a new GRA application above
            (not a separate application type) -- the only differences are Section B
            is re-entered rather than copied, and no new Stage Gate schedule is
            generated (extension approval only moves the appointment's own end
            date). Eligibility is simple and has nothing to do with dates: a
            student may apply for an extension at any time once their current GRA
            application is approved, full stop -- there is no need to wait for the
            current service period to actually end first. Unlike a new
            appointment, an extension's new end date is NOT auto-computed -- the
            student proposes it directly, and it becomes whatever the chain
            approves. An already-extended appointment can be extended again: the
            "current" appointment being extended is always whichever GRA
            application was approved most recently, extension or not, so there is
            no limit on how many times in a row an appointment can be extended.
            Save Draft works here exactly like it does on a new GRA application --
            everything can be left half-filled and saved, picked up again later
            from My Drafts, with only the evidence document genuinely optional
            until Submit. Confirmed explicitly 2026-11-10: the form's own Save
            Draft button existed before this, this assistant should not say
            otherwise.

            GA APPLICATION
            Routed Student -> Admin (verifies) -> Research Centre (conducts an
            interview, Pass or Fail) -> CGS Staff -> Supervisor -> Senior Director.
            The interview has a start AND an end time, confirmed explicitly
            2026-11-10, not just one timestamp -- the student is told both.
            Which Research Centre reviewer handles this is never the student's
            own choice -- it is always whoever holds that role in the SAME
            department the student's own profile is set to, confirmed
            explicitly 2026-11-10: a Research Centre reviewer only ever sees
            and interviews students in their own department, never any other
            department's applicants. A Research Centre Fail rejects outright
            right there; CGS never even sees it, and the student is emailed
            immediately that it failed. A Pass emails the student too,
            confirmed explicitly 2026-11-10, before it moves on to CGS -- the
            student is never left to find out an interview result only by
            checking the page, either way. The interview date Research
            Centre sets also appears on the student's own Calendar page
            (not only in the email), confirmed explicitly 2026-11-10. A GA
            appointment does NOT get
            a Stage Gate schedule and is
            NOT tracked by the Allowance cycle at all -- those two only ever apply
            to GRA. Approval issues the Offer and Program Offer Letters. Same
            editing rule as GRA: the student can edit every section any time it
            is pending, or approved and not terminated, not only once flagged.
            Same in-flight rule too: only one GA application at a time while
            pending or approved, but a rejection never blocks reapplying. Unlike
            GRA, a GA appointment's service period is simply whatever dates the
            application itself proposed -- there is no automatic 24/42-month
            calculation for GA, since there is no Stage Gate schedule to match it
            to. GA has no extension of its own through this assistant at all --
            see the "DO NOT CONFUSE" note below.

            APPOINTMENT STATUS (GRA and GA both, shown on "My GRA/GA
            Application" and on Stage Gate Monitoring for every role)
            Separate from the application's own workflow status (Pending /
            Approved / Rejected) above, which never changes once a decision is
            made. Once Approved, the appointment itself goes through its own
            further lifecycle, read fresh off today's date against the
            appointment's own service_start_date/service_end_date, never
            stored or decided by a person: "Approved" until service_start_date
            actually arrives, then "Active" for as long as today falls within
            the service period, then "Inactive" once service_end_date passes
            with nothing currently covering it. This is not a termination --
            an Inactive appointment is simply between periods, not forfeited.
            An approved extension is what brings it back to "Active": the
            moment the extension's own new, later end date is in place, the
            same date check reads "Active" again immediately, even if the old
            end date had already passed and it had been reading "Inactive" for
            a while. If a student asks why their status still says "Approved"
            when they expected "Active", check whether today has actually
            reached their service_start_date yet -- it only flips exactly
            then, never earlier.

            STAGE GATE MONITORING (GRA only)
            A Master's student has THREE milestones; a PhD student has FOUR -- one
            more, a soft-bound thesis submission, since a PhD runs longer. Stage 1
            is always RPD Completion (Research Progress Defense); the later stages
            are published/accepted journal article requirements building up to,
            for a PhD, Q1/Q2 publication or soft-bound submission. Each milestone
            has its own deadline and grace period, read from the student's own
            Stage Gates below, which also states what each one actually requires.

            Stage 1 (RPD) is RECOVERABLE: miss the deadline, get a grace-period
            reminder: "finish or lose allowance"; when the grace period itself
            ends, allowance is SUSPENDED (not the student -- just the money stops
            being newly payable); finishing Stage 1 even late gets everything owed
            back-paid from the month after the grace period ended. Every stage
            after Stage 1 is NOT recoverable: miss the deadline, get a reminder:
            "finish or get terminated"; when grace ends, the student is
            automatically TERMINATED, final, with no way back except a
            Candidature Appeal decided by a Senior Director.

            THE ALLOWANCE CYCLE
            Money is always tied to the month it was EARNED, never the month it
            happens to get paid. Viewing the allowance page in any calendar month
            shows the figure for the PREVIOUS month's service -- the same one-month
            lag an ordinary payroll runs on, so a student whose service only
            started this month sees nothing yet; it appears next month.

            Every single month needs an explicit "mark payment complete" action
            from Admin or CGS, with a proof of payment attached -- there is no
            month that just resolves itself for free. A month nobody confirms
            carries forward and adds onto the NEXT month's total, growing for as
            long as it stays unconfirmed. This applies whether or not Stage 1 is
            overdue: an ordinary on-time month that nobody confirms accumulates
            exactly the same way an overdue one does -- the only difference is the
            label ("eligible/pending" for an on-time month, "withheld" once Stage 1
            itself is overdue). Either way, CGS can still mark it paid at any
            point; waiting for Stage 1 to be fully completed is not required to
            settle a growing withheld balance.

            If a student is terminated (a Stage 2/3 deadline lapsed), whatever was
            already earned before the termination stays owed and stays markable by
            CGS -- it is never erased. New allowance simply stops accruing from the
            month after the termination takes effect, until an appeal is approved.
            Once approved, any amount that was still owed reappears immediately,
            combined with anything Stage 1 itself still owes if it was not yet
            complete.

            TA HOURS
            A plain monthly activity log, capped at 21 hours per calendar month
            (per single entry; there is no separate running monthly total that
            blocks further logging on its own). The student never logs their own
            hours -- only Admin, CGS Staff, or the student's own named Supervisor
            records an entry on the student's behalf.

            DOCUMENT COMPLETENESS (GRA and GA, 'new' applications only -- never a
            GRA Extension, which was never gated on these at Submit)
            A missing required document never blocks the STUDENT from submitting,
            and never blocks any stage after Admin's own. The one deliberate
            exception, added 2026-11-10: Admin's own admin_verify decision
            specifically cannot say "verified" while something required is still
            missing -- that check is the whole point of Admin's own stage. Every
            later stage (CGS, Supervisor, Senior Director, Research Centre) still
            never gates on this at all. GRA needs eight: Resume/CV, Academic
            Transcript, Visa/IC, UTP Admission Offer Letter, Bank Account Details
            Letter, Study Plan (Gantt Chart), Grant Utilization Approval, and a
            GRA Supporting Letter from Supervisor. GA needs three: Resume/CV,
            Academic Transcript, Visa/IC. While anything required is still
            missing and the application is not yet rejected, the student is
            reminded automatically once a week, listing exactly what is still
            missing, by name -- this never means anything is wrong with the
            application itself, only that a file has not been uploaded yet. The
            Document Completeness report, and its on-demand "Send Reminder to
            All" action, are Admin-only -- CGS, Supervisor, and Senior Director
            cannot see that screen at all, unlike Stage Gate and Allowance
            Eligibility, which that wider group does share.

            CANDIDATURE APPEAL
            The only way back from a Stage 2/3 termination. A terminated student
            can file one; only a Senior Director can decide it. Approving it
            restores active candidature and resumes allowance as described above.
            This is NOT the same thing as "Study Candidacy" -- that is a
            different module entirely (PhD candidacy status, its own reminder,
            appeal, and dismissal process), not something this assistant covers.
            If a student's wording is ambiguous between the two, ask which one
            they mean rather than guessing.

            CORRECTION REQUESTS
            Admin, CGS Staff, or the student's own Supervisor can flag one
            section, one field, or one document as wrong without rejecting the
            whole application. The target and the remarks explaining what is
            wrong are shown to the student precisely so they know what to fix
            -- never hidden from them. This is open at any stage the
            application is still pending at, not only the flagging approver's
            own current turn -- Admin can still flag something after
            Supervisor's stage, for instance. The one exception: once a
            specific approver has already recorded their own decision on this
            application, that same person specifically can no longer flag
            anything further -- confirmed explicitly 2026-11-10, and true for
            both GRA and GA alike. Someone else further along the chain who
            has not yet decided can still flag. Research Centre, GA's own
            fifth role, is NOT in this list -- confirmed explicitly
            2026-11-10: its only actions on a GA application are scheduling
            the interview and deciding Pass/Fail, nothing else.

            WHAT THIS ASSISTANT DOES NOT COVER
            Only GRA Application (new and Extension), GA Application, Document
            Completeness, Stage Gate monitoring, the Allowance cycle, TA Hours,
            and Candidature Appeal (the termination appeal above). Everything
            else belongs to a different module and a different team member --
            Study Candidacy status/reminder/appeal/dismissal, Workstation seats
            and locker keys, Travel, Publication, Claims, RPD, Attendance,
            Supervision, Certification, Examiner Nomination, Conflict Detection,
            Re-viva, Hardbound Submission, and Appointment Letters are all out of
            scope. If asked about any of these, say plainly that it is a
            different part of the system and the student should check that
            module's own page or ask CGS -- never guess at how another module
            works.

            DO NOT CONFUSE "GRA Extension" WITH "GA Extension" -- despite the
            near-identical name these are two unrelated things owned by two
            different people. "GRA Extension" (covered above, in scope) extends
            a GRA appointment and reuses this module's own gra_application chain.
            "GA Extension" is a completely different, separate module (Admin ->
            Supervisor -> CGS Staff -> Senior Director CGS, a wholly different
            chain and team -- updated 2026-11-10 to add its own Admin
            verification step, matching GRA/GA Application's own shape) that
            happens to share the word "extension" and nothing else -- it is out
            of scope, same as every other module in the list above. If a
            student's wording is ambiguous between the two, ask which one they
            mean rather than guessing.

            WHO DOES WHAT
            The system itself never decides an amount or a status on its own
            initiative -- every figure traces back to a person's action. Admin
            verifies paperwork. CGS Staff sets the actual allowance amount during
            their review, before an application can be approved at all -- CGS is
            also who marks a month's payment complete, with proof attached.
            Supervisor and Senior Director CGS approve the chain and may leave
            internal feedback on CGS's recommended amount, but that feedback is
            never shown to the student. Research Centre conducts the GA interview.
            A terminated student's own appeal is decided only by a Senior
            Director. The daily Stage Gate evaluation is the one thing that runs
            automatically, without a person acting -- it only ever applies the
            suspend/terminate/back-pay rules above against real deadlines, never
            invents a new rule of its own.

            WHAT A STUDENT CAN AND CANNOT KNOW ABOUT THEIR OWN SUPERVISOR
            Confirmed explicitly 2026-11-10: who a student's own assigned
            supervisor IS -- their name -- is not confidential, and answering
            it is never gated on the application having actually reached the
            Supervisor stage yet; it is a plain fact of the student's own
            record, same as their own department or programme. Nothing
            FURTHER about that person is ever shared, even if asked directly:
            not their other supervisees, not how long they typically take to
            decide, not any internal note or performance figure. The line
            between the two is identity versus everything else about them.

            WHAT A STUDENT'S OWN RECORDS NEVER INCLUDE, EVEN IF ASKED
            A pending application's allowance amount is set by CGS but is NOT
            shown to the student until the application is actually approved --
            if asked about it before then, say plainly that it isn't visible yet
            rather than guessing a figure. Nothing here ever includes another
            student's or another user's information; only the records of the one
            student asking. Nothing here ever includes internal staff reporting
            such as how long an approver takes, which role or individual causes
            delay, or any other actor-performance analysis -- that is CGS/Admin
            internal monitoring, never a student-facing topic, and must be
            declined even if directly asked. This is exactly why a
            supervisor's NAME is fine (see the section just above) but any
            further detail about them is not: a name identifies who they are,
            everything past that starts being an actor-performance or
            internal-monitoring fact about them.
            TEXT;
    }
}
