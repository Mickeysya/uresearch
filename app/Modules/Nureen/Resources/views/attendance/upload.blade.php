@extends('core::layouts.app')

@section('title', 'Upload Attendance')

@section('content')
{{--
    This page was already a three-step procedure written as one long scroll --
    the headings literally read "1.", "2.", "3.". Wrapping each in a .fstep
    makes the stepper state what the numbering was already claiming, and puts
    the column reference on its own screen instead of between the thing CGS
    downloads and the thing they upload.

    Steps 1 and 2 carry no fields, which the stepper handles: the per-step gate
    passes trivially and the generated review skips a step with nothing filled
    in, so the review shows the chosen file and nothing else.
--}}
<div class="card-container-inline">
    <x-core::page-header
        title="Upload Attendance" />

    {{--
        The automated side: attendance:sync pulls from whichever source is
        configured (an inbox folder standing in for a nightly Power Automate
        export, the API endpoint, or simulated data) every night at 02:00,
        or on demand via Sync Now. Deliberately NOT part of the .fstep
        wizard below -- it is status plus one action, not a form to step
        through, and keeping it a plain card means form-stepper's single
        `document.querySelector('form[data-stepper]')` still finds only the
        manual-upload form.
    --}}
    <div class="card card-wide" style="margin-bottom: var(--space-6);">
        <h3>Automated Sync</h3>
        <p class="queue-meta">
            Students record attendance in the university's Power Apps app. In production
            a nightly Power Automate flow exports new records here automatically; this FYP
            simulates that, since there is no live UTP access. The upload below is kept as
            a manual fallback.
        </p>

        <div class="table-scroll">
            <table class="spec-table">
                <tbody>
                    <tr>
                        <td>Active source</td>
                        <td>{{ $activeSource }}</td>
                    </tr>
                    <tr>
                        <td>Last run</td>
                        <td>
                            @if ($lastSync)
                                {{ $lastSync->started_at->diffForHumans() }}
                                ({{ $lastSync->started_at->format('j M Y, g:ia') }})
                            @else
                                Never run yet.
                            @endif
                        </td>
                    </tr>
                    @if ($lastSync)
                        <tr>
                            <td>Last summary</td>
                            <td>
                                Read {{ $lastSync->rows_read }}, added {{ $lastSync->rows_added }},
                                updated {{ $lastSync->rows_updated }}, rejected {{ $lastSync->rows_rejected }}.
                                {{ $lastSync->rows_newly_at_risk }} newly flagged at-risk.
                            </td>
                        </tr>
                        @if (! empty($lastSync->rejection_reasons))
                            <tr>
                                <td>Rejected rows</td>
                                <td>
                                    <ul>
                                        @foreach (array_slice($lastSync->rejection_reasons, 0, 5) as $reason)
                                            <li>{{ $reason }}</li>
                                        @endforeach
                                    </ul>
                                    @if (count($lastSync->rejection_reasons) > 5)
                                        <p class="field-hint">
                                            and {{ count($lastSync->rejection_reasons) - 5 }} more.
                                        </p>
                                    @endif
                                </td>
                            </tr>
                        @endif
                    @endif
                </tbody>
            </table>
        </div>

        <form method="POST" action="{{ route('attendance.sync-now') }}" style="margin-top: var(--space-4);">
            @csrf
            <button type="submit" class="btn-secondary">Sync Now</button>
        </form>
    </div>

    <div class="card card-wide">
        <h3 style="margin-top: 0;">Manual Fallback</h3>
        <form method="POST" action="{{ route('attendance.upload') }}" enctype="multipart/form-data" data-stepper>
            @csrf

            <fieldset class="fstep" data-label="Get the template">
            <p class="fstep-hint">
                Export attendance from UTrace, then bring it here. The first row has to
                match exactly, so it is easier to fill in a blank sheet than to reshape
                an export by hand. Both files already contain the header row and two
                filled-in examples.
            </p>

            <p class="att-template-row">
                <a href="{{ route('attendance.template') }}" class="btn-secondary">
                    Download Excel template (.xlsx)
                </a>
                <a href="{{ route('attendance.template', ['format' => 'csv']) }}" class="btn-secondary">
                    Download CSV template (.csv)
                </a>
            </p>

            <p class="field-hint">
                Prefer the Excel template. It keeps <code>period_end</code> as a real
                date, whereas a CSV re-saved through Excel often has its dates
                rewritten into the local format.
            </p>
            </fieldset>

            <fieldset class="fstep" data-label="Check the columns">
            <p class="fstep-hint">
                Four columns, in this order. Anything after the fourth is ignored, so a
                UTrace export with extra trailing columns still works.
            </p>

            <div class="table-scroll">
                <table class="spec-table">
                    <thead>
                        <tr><th>Column</th><th>Meaning</th><th>Example</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>matric_no</code></td>
                            <td>The student's matric number, exactly as it is recorded in the portal. A row whose matric number matches nobody is skipped and reported.</td>
                            <td>22001001</td>
                        </tr>
                        <tr>
                            <td><code>period_end</code></td>
                            <td>The last day of the period the row covers. <b>YYYY-MM-DD</b>.</td>
                            <td>{{ now()->startOfMonth()->subMonth()->endOfMonth()->format('Y-m-d') }}</td>
                        </tr>
                        <tr>
                            <td><code>sessions_attended</code></td>
                            <td>Sessions the student attended in that period.</td>
                            <td>18</td>
                        </tr>
                        <tr>
                            <td><code>sessions_total</code></td>
                            <td>Sessions held in that period. Must be more than zero, and not less than <code>sessions_attended</code>.</td>
                            <td>20</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="field-hint">
                The percentage is worked out from the two session counts on every save.
                There is no column for it, and a figure typed into the sheet is ignored.
            </p>
            </fieldset>

            <fieldset class="fstep" data-label="Upload">
            <p class="fstep-hint">
                Re-uploading a period a student already has on file replaces that row
                rather than duplicating it, so a corrected export can simply be
                uploaded again.
            </p>

            <label for="csv_file">Attendance file (.xlsx, .xls or .csv)</label>
            <input type="file" name="csv_file" id="csv_file" accept=".csv,.txt,.xlsx,.xls" required
                   class="@error('csv_file') is-invalid @enderror">
            @error('csv_file') <p class="field-error">{{ $message }}</p> @enderror

            <p class="field-hint">
                Good rows are saved even when others are skipped, and anything skipped is
                listed back with the row number and the reason, so a partly-wrong file is
                worth uploading and correcting rather than starting over.
            </p>
            </fieldset>

            <button type="submit">Upload &amp; Process</button>
        </form>
    </div>
</div>

@include('core::partials.form-stepper')
@endsection
