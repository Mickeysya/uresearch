@extends('core::layouts.app')

@section('title', 'GRA Scheme: Terms and Conditions')

@push('head')
    @include('haziq::partials.styles')
@endpush

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Graduate Research Assistant (GRA) Scheme: Terms and Conditions"
        subtitle="{{ 'Read this before starting your '.($intent === 'extension' ? 'extension request' : 'GRA application').'. You only need to accept this once; it covers every GRA application and extension you submit afterwards.' }}" />

    <div class="card card-wide">
        <p style="font-style: italic;">
            Universiti Teknologi PETRONAS reserves the right to add, amend or made any alterations to the
            Graduate Research Assistant (GRA) Scheme Terms and Conditions as and when necessary.
        </p>

        @if ($intent === 'extension')
            <p><b>Eligibility Criteria to apply for Extension of Graduate Research Assistant (GRA) Scheme are as follows:-</b></p>
            <p>a. Registered as a full time on-campus postgraduate student and fulfilled the UTP admission criteria.</p>
            <p>b. Shows research capability in the proposed research project.</p>
            <p>c. Able to carry out the work to be assigned by the Project Leader (PL).</p>
            <p>d. Applicant must be appointed as GRA by Universiti Teknologi PETRONAS (UTP).</p>
            <p>e. The UTP GRA scheme is open to full time on-campus local and international postgraduate candidates.</p>
            <p>f. Successfully completed relevant stage gate(s).</p>
            <p><b>(ii) Academic requirement for Graduate Research Assistant (GRA) Scheme</b></p>
        @else
            <p><b>Eligibility Criteria to apply for Graduate Research Assistant (GRA) Scheme are as follows:-</b></p>
            <p>(i) Registered as a full time on-campus postgraduate student and fulfilled the UTP admission criteria.</p>
            <p>(ii) Shows research capability in the proposed research project.</p>
            <p>(iii) Able to carry out the work to be assigned by the Project Leader (PL).</p>
            <p>(iv) Applicant must be appointed as GRA by Universiti Teknologi PETRONAS (UTP).</p>
            <p>(v) The UTP GRA scheme is open to full time on-campus local and international postgraduate candidates.</p>
            <p><b>(vi) Academic requirement for Graduate Research Assistant (GRA) Scheme</b></p>
        @endif

        <table class="haziq-table">
            <thead><tr><th>Level</th><th>Local Student</th><th>International Student</th></tr></thead>
            <tbody>
                <tr><td>MSc</td><td>CGPA : 3.25/4.00 and above</td><td>CGPA : 3.25/4.00 and above or equivalent from recognized university</td></tr>
                <tr><td>PhD</td><td>Coursework : CGPA : 3.25/4.00<br>Research : Pass</td><td>Coursework : CGPA : 3.25/4.00<br>Research : Pass</td></tr>
            </tbody>
        </table>

        <p><b>{{ $intent === 'extension' ? '(iii)' : '(vii)' }} Duration of the Graduate Research Assistant (GRA) Scheme.</b>
            The GRA duration is counted start from admission date.</p>

        <table class="haziq-table">
            <thead><tr><th>Level of study</th><th>Maximum duration of GRA (Normal scheme)</th></tr></thead>
            <tbody>
                <tr><td>MSc</td><td>2 years (24 months)</td></tr>
                <tr><td>PhD</td><td>3.5 years (42 months)</td></tr>
            </tbody>
        </table>

        <p>❖ The minimum and maximum duration shall be at least three (3) months and twelve (12) months. All
            applications of GRA appointment must be submitted at least one (1) month for normal GRA scheme and
            three (3) months for exceeded duration and revision allowance.</p>
        <p>❖ GRA Appointment start date is either 1st or 16th of the month. The GRA Appointment end date is either
            15th or end of the month. Backdated appointment OR redundant agreement is not allowed.</p>

        <p><b>{{ $intent === 'extension' ? '(iv)' : '(viii)' }} Graduate Research Assistant (GRA) Scheme allowance rate</b></p>
        <table class="haziq-table">
            <thead><tr><th>Level of study</th><th>GRA Allowance (Normal rate)</th></tr></thead>
            <tbody>
                <tr><td>MSc</td><td>Based on grant affordability</td></tr>
                <tr><td>PhD</td><td>Based on grant affordability</td></tr>
            </tbody>
        </table>

        <p><b>{{ $intent === 'extension' ? '(v)' : '(ix)' }} Graduate Research Assistant (GRA) Scheme Work Service Claim Duration (Payment schedule)</b></p>
        <table class="haziq-table">
            <thead><tr><th>No.</th><th>Monthly Claim Work</th><th>Payment for the month of</th><th>Payment</th></tr></thead>
            <tbody>
                <tr><td>1</td><td>16 December – 15 January</td><td>January</td><td rowspan="12">Once a month on the 23rd of every month</td></tr>
                <tr><td>2</td><td>16 January – 15 February</td><td>February</td></tr>
                <tr><td>3</td><td>16 February - 15 March</td><td>March</td></tr>
                <tr><td>4</td><td>16 March - 15 April</td><td>April</td></tr>
                <tr><td>5</td><td>16 April – 15 May</td><td>May</td></tr>
                <tr><td>6</td><td>16 May – 15 June</td><td>June</td></tr>
                <tr><td>7</td><td>16 June – 15 July</td><td>July</td></tr>
                <tr><td>8</td><td>16 July – 15 August</td><td>August</td></tr>
                <tr><td>9</td><td>16 August – 15 September</td><td>September</td></tr>
                <tr><td>10</td><td>16 September – 15 October</td><td>October</td></tr>
                <tr><td>11</td><td>16 October – 15 November</td><td>November</td></tr>
                <tr><td>12</td><td>16 November – 15 December</td><td>December</td></tr>
            </tbody>
        </table>

        <p><b>{{ $intent === 'extension' ? '(vi)' : '(x)' }} Research Progress Defense (RPD) for Graduate Research Assistant (GRA) scheme</b></p>
        <table class="haziq-table">
            <thead><tr><th>Level of study</th><th>Maximum duration for GRA funding until suspension</th><th>Maximum Reimbursement of allowance</th></tr></thead>
            <tbody>
                <tr><td>MSc</td><td>3 months from admission date</td><td>3 months from the date of successful RPD</td></tr>
                <tr><td>PhD</td><td>6 months from admission date</td><td>3 months from the date of successful RPD</td></tr>
            </tbody>
        </table>

        <p><b>{{ $intent === 'extension' ? '(vii)' : '(xi)' }} Calculation of TA hour for Graduate Research Assistant (GRA) Scheme</b></p>
        <p>❖ In lieu of the waived tuition fee you are required to carry out the task as a Teaching Assistant (TA
            hour) for a period of five (5) to six (6) hours per week (totaling up to 84 hours per semester) as
            tutor or laboratory demonstrator as assigned by the Resource Planning &amp; Performance Department.</p>
        <p>❖ Exemption of TA hours will only be given if your tuition fee payment is paid by the project / grant.
            Generally, the maximum TA hours is not more than 21 TA hours per month.</p>
        <p>❖ Extra TA Hours cannot be carried forward {{ $intent === 'extension' ? 'into' : 'to' }} the next semester.</p>

        <table class="haziq-table">
            <thead><tr><th>TA Activity</th><th>&nbsp;</th><th>Descriptions</th><th>Approver</th><th>Claimable TA Hours (hrs)</th></tr></thead>
            <tbody>
                <tr><td rowspan="2">Tutorial</td><td>Tutorial</td><td>To conduct tutorial that has been prescheduled as part of Student Leaning Time (SLT)</td><td>Course Coordinator / Lecturer</td><td>1.25 x Tutorial hours</td></tr>
                <tr><td>Focused Group Tutorial</td><td>To conduct tutorial for specific groups of students that require extra attention</td><td>Department Chair</td><td>—</td></tr>
                <tr><td>&nbsp;</td><td>Conduct Special Training</td><td>To organise and conduct special training on specific topics, simulation software or tools for assisting students on project-based courses. (e.g. FYP/ETP/STP/Capstone, MSc by CW project).</td><td>Dean of CGS</td><td>2 x Training hours</td></tr>
                <tr><td>Laboratory</td><td>Lab Demonstration</td><td>To conduct teaching lab demonstration that has been prescheduled in the timetable as part of SLT</td><td>Course Coordinator / Lecturer</td><td>1.25 x Lab hours</td></tr>
                <tr><td>Evaluation of Assessment</td><td>Evaluating short assignment / assessment</td><td>To conduct marking of quizzes, short assignments, and brief lab report as per marking scheme provided by course coordinator / lecturer.</td><td>Course Coordinator / Lecturer</td><td>2 minutes x no of student</td></tr>
                <tr><td rowspan="2">Invigilation / Coordination</td><td>Invigilation of Final Exam / Test</td><td>To conduct invigilation of final exam or test that has been prescheduled by REX unit or course coordinator / lecturer.</td><td>Manager (REX) or Course Coordinator / Lecturer</td><td>Based on approved hours</td></tr>
                <tr><td>Course Management</td><td>1. Project-based courses: to assist project-based course coordinator in managing sessions for evaluation and assessment purposes (e.g. FYP/ETP/STP/Capstone, MSc by CW project). 2. Courses with large no of students (&ge;80 students) with lab component/field work: to assist course coordinator / lecturer on course management which include grouping and scheduling for labs and field work sessions.</td><td>Course / PG Coordinator</td><td>—</td></tr>
            </tbody>
        </table>

        <div class="haziq-repeatable-row" style="grid-template-columns: 1fr; gap: 10px; margin-top: 16px;">
            <p><b>Certification.</b> By accepting below, you certify that the statement and information in the
                application form are true and correct, and you understand and agree that falsification or material
                omission of information provided herein may result in your GRA contract being terminated.</p>
        </div>

        <form method="POST" action="{{ route('gra-application.terms.accept') }}" style="margin-top: 18px;">
            @csrf
            <input type="hidden" name="intent" value="{{ $intent }}">

            <label style="display: flex; align-items: flex-start; gap: 8px; font-weight: normal;">
                <input type="checkbox" name="agree" value="1" required style="margin-top: 3px;">
                <span>I have read and agree to the GRA Scheme Terms and Conditions above.</span>
            </label>
            @error('agree') <p class="field-error">{{ $message }}</p> @enderror

            <button type="submit" style="margin-top: 12px;">Agree &amp; Continue</button>
        </form>
    </div>
</div>
@endsection
