@extends('core::layouts.guest')

@section('title', 'Postgraduate Workstation Term of Use')

{{--
    PART 4 #1. On the guest layout, same reasoning as confirm.blade.php --
    every email in this module links here, so it has to render for whoever
    opens the link, logged in or not.
--}}
@section('content')
    @include('chloe::workstation._assistant-widget')

{{--
    The standard Workstation header (x-core::page-header): a plain-text "← Back" above the title (only
    when there is somewhere to go back to -- never for a guest) and the blue Home button at the top
    right. Back/Home targets per visitor: Support\WorkstationHeaderLinks. The title replaces the two
    headings the card used to carry ("Postgraduate Workstation" / "Term of Use").
--}}
{{-- Page-scoped: the header sits in a column the same width as the (unchanged) 680px card, so the two line up. --}}
<style>
    .wst-column { width: 100%; max-width: 680px; }
</style>

<div class="card-container">
    <div class="wst-column">
    <x-core::page-header title="Postgraduate Workstation Term of Use" :back="$backUrl" :back-history="true">
        <a href="{{ $homeUrl }}"><button type="button">Home</button></a>
    </x-core::page-header>

    <div class="card card-wide">

        <ol style="line-height: 1.6;">
            <li>Any loan requisition of postgraduate workstation located at Block J &amp; Block N shall be made by students through Centre for Graduate Studies (CGS) of Universiti Teknologi PETRONAS (UTP).</li>
            <li>The workstation loan requisition is subjected to the availability of the workstation and endorsement of the student's supervisor.</li>
            <li>Students are required to acknowledge CGS on acceptance.</li>
            <li>Student shall not perform any modification on the workstation/facilities.</li>
            <li>Students are not allowed to bring/ keep any harmful devices/material to workstation area.</li>
            <li>The workstation and facilities provided with the workstation/room must be handled with care. Any damages or lost must be reported immediately to the CGS. The occupant shall take full responsibility for any damages due the negligence and loses. The occupant shall be billed for the repairing cost if the damage is due to the negligence of the occupants.</li>
            <li>The workstation and UTP facilities shall not be taken out/removed of the designated area/room.</li>
            <li>CGS has the right to do random inspection from time to time to make sure that occupants is taking good care of the workstation and facilities.</li>
            <li>CGS has the right to terminate the loan and request the occupants to vacate the area within five (5) working days upon date of notice.</li>
            <li>Occupants shall not use or permit the workstation/facilities/room to be used for any unlawful or immoral purposes.</li>
            <li>The postgraduate workstation is amenities provided to UTP students. Please be considerate and not to cause or permit anything which may or is likely to be a nuisance, annoyance or danger to him/herself and the other students.</li>
            <li>The workstation can be accessed on Monday &ndash; Friday (7.00 am &ndash; 6.00 pm) only. The last person who leaves the room should switch off all the appliances (lights, fans, air-conditioners, etc.). University will not be responsible for any incidents (e.g., injury) happened in the workstation room which outside the operational period as per mentioned above.</li>
            <li>Students have a responsibility to take care of their own health and safety and that of others who may be affected by their actions.</li>
            <li>The workstation is for research/study purpose only. Any activities which not related to research/study (i.e., sleeping, cooking, etc.) is strictly prohibited.</li>
            <li>Only approved occupants entitled to use the workstation and its facilities. Any belongings stationed on the unauthorized workstation/area will be confiscated without prior notice.</li>
            <li>
                The loan will be automatically terminated if the student has been put under/placed in any of the reasons as per stated below. The occupants to vacate the area within five (5) working days upon receiving notice from university.
                <ol type="a" style="margin-top: 8px;">
                    <li>Deferment</li>
                    <li>Change Programme to Coursework</li>
                    <li>Change Programme Mode to Part-Time</li>
                    <li>Graduated</li>
                    <li>Withdrawn</li>
                    <li>Dismissal</li>
                    <li>De-Registered</li>
                    <li>Under disciplinary action</li>
                    <li>Absent without prior approval</li>
                    <li>Any other reason(s) deemed appropriate by the University</li>
                </ol>
            </li>
            <li>Maximum loan duration is subject to management of universities and will be automatically terminated. Students who intend to continue the loan must reapply (subject to the availability).</li>
            <li>CGS also has the authority to change or amend any of the terms and conditions listed above deemed necessary without any prior notice.</li>
        </ol>

        <p style="color: var(--text-grey); font-size: 13px; margin-top: 24px;">
            Universiti Teknologi PETRONAS<br>
            Centre For Graduate Studies 2022<br>
            First Edition 2022
        </p>

        <div class="card-divider"></div>

        <a href="{{ route('workstation.terms.pdf') }}"><button type="button">Download PDF</button></a>
        {{--
            Shown only while no real PDF is found in
            WorkstationTermsController::ORIGINAL_PDF_DIRECTORY -- the
            moment one is placed there (either filename spelling, or any
            .pdf whose name contains "Term of Use"), downloadPdf() serves
            it directly and this line stops rendering on its own.
        --}}
        @unless ($hasOriginalPdf)
            <p style="color: var(--text-grey); font-size: 12px; margin-top: 6px;">
                Generated from the text of the Term of Use (First Edition).
            </p>
        @endunless
    </div>
    </div>
</div>

@if ($backUrl)
    {{-- Back = real browser history when the previous page is another page of THIS application; otherwise the link just follows its href (also what happens with JavaScript off). --}}
    <script @cspNonce>
        document.querySelectorAll('[data-browser-back]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                if (! document.referrer || window.history.length < 2) return;

                var previous;
                try { previous = new URL(document.referrer); } catch (e) { return; }

                if (previous.origin === window.location.origin && previous.pathname !== window.location.pathname) {
                    event.preventDefault();
                    window.history.back();
                }
            });
        });
    </script>
@endif
@endsection
