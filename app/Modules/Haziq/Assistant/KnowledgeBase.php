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
            Offer and Admission Letters automatically.

            GA APPLICATION
            Routed Student -> Admin (verifies) -> Research Centre (conducts an
            interview, Pass or Fail) -> CGS Staff -> Supervisor -> Senior Director.
            A Research Centre Fail rejects outright right there; CGS never even
            sees it. A GA appointment does NOT get a Stage Gate schedule and is
            NOT tracked by the Allowance cycle at all -- those two only ever apply
            to GRA. Approval issues the Offer and Program Offer Letters.

            STAGE GATE MONITORING (GRA only)
            Three milestones with a deadline and a grace period each. Stage 1 (RPD)
            is RECOVERABLE: miss the deadline, get a grace-period reminder: "finish
            or lose allowance"; when the grace period itself ends, allowance is
            SUSPENDED (not the student -- just the money stops being newly payable);
            finishing Stage 1 even late gets everything owed back-paid from the
            month after the grace period ended. Stages 2 and 3 are NOT recoverable:
            miss the deadline, get a reminder: "finish or get terminated"; when
            grace ends, the student is automatically TERMINATED, final, with no
            way back except a Candidature Appeal decided by a Senior Director.

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
            A plain monthly activity log, capped at 21 hours per calendar month.

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
            Any approver can flag one section, one field, or one document as
            wrong without rejecting the whole application. The target and the
            remarks explaining what is wrong are shown to the student precisely
            so they know what to fix -- never hidden from them.

            WHAT THIS ASSISTANT DOES NOT COVER
            Only GRA Application, GA Application, Stage Gate monitoring, the
            Allowance cycle, TA Hours, and Candidature Appeal (the termination
            appeal above). Everything else belongs to a different module and a
            different team member -- Study Candidacy status/reminder/appeal/
            dismissal, Workstation seats and locker keys, Travel, Publication,
            Claims, RPD, Attendance, GA Extension, Supervision, Certification,
            Examiner Nomination, Conflict Detection, Re-viva, Hardbound
            Submission, and Appointment Letters are all out of scope. If asked
            about any of these, say plainly that it is a different part of the
            system and the student should check that module's own page or ask
            CGS -- never guess at how another module works.

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
            declined even if directly asked.
            TEXT;
    }
}
