@extends('core::layouts.app')

@section('title', 'Dismissal Upload')

@include('chloe::candidacy.wca._assets')

@section('content')
    <div class="wca-page">
        <x-core::page-header title="Dismissal Upload" subtitle="Upload the Registry's confirmed list. Only students who are still dismissal candidates are dismissed." :back="auth()->user()->role === \App\Modules\Core\Support\Role::REGISTRY ? route('dashboard') : route('candidacy.cgs.dismissals')" :back-history="false">
            <a href="{{ \App\Modules\Chloe\Support\CandidacyHeaderLinks::home() }}"><button type="button">Home</button></a>
        </x-core::page-header>

        @include('chloe::candidacy.wca._flash')

        @if ($result)
            <section class="wca-card wca-result" role="status" aria-live="polite">
                <h3>Upload applied</h3>
                <p><strong>{{ $result['dismissed'] }}</strong> student(s) dismissed, <strong>{{ $result['skipped'] }}</strong> row(s) skipped, from {{ $result['rows'] }} row(s) in <em>{{ $result['file'] }}</em>.</p>
                @if ($result['changed'] > 0)
                    <p class="wca-muted">{{ $result['changed'] }} student(s) shown as "Will be dismissed" in the preview were skipped because they are no longer candidates.</p>
                @endif
                <p class="wca-muted wca-small">Each dismissed student leaves the Dismissal List and has been sent an e-mail and a bell notice. Nothing was sent to CGS.</p>
            </section>
        @endif

        <section class="wca-card" aria-labelledby="wca-up-1">
            <h3 id="wca-up-1">Step 1 · Choose the file</h3>
            <p class="wca-muted wca-small">An .xlsx or .csv file (up to 5 MB and {{ \App\Modules\Chloe\Services\CandidacyDismissalUpload::MAX_ROWS }} rows) with a column named "Student ID". Other columns are ignored, so the file exported from the Dismissal List works unchanged. Nothing is saved until you confirm.</p>
            <form method="POST" action="{{ route('candidacy.cgs.dismissal-upload.preview') }}" enctype="multipart/form-data" class="wca-upload-form">
                @csrf
                <div class="wca-field"><label for="wca-up-file">File</label><input id="wca-up-file" class="wca-input" type="file" name="file" accept=".xlsx,.csv" required aria-describedby="wca-up-file-err">
                    @error('file')<p class="wca-error" id="wca-up-file-err" role="alert">{{ $message }}</p>@enderror</div>
                <div class="wca-actions"><button type="submit" class="wca-btn is-primary">Preview</button></div>
            </form>
        </section>

        @if ($rows !== null)
            <section class="wca-card" aria-labelledby="wca-up-2">
                <h3 id="wca-up-2">Step 2 · Check the preview</h3>
                <p class="wca-muted wca-small">File: <strong>{{ $pending['file'] }}</strong>. Nothing has been saved. Only the rows marked "Will be dismissed" are applied.</p>
                <ul class="wca-sumlist" aria-label="Summary">
                    <li><strong>{{ $counts['total'] }}</strong> rows</li>
                    @foreach ($labels as $key => $label)<li class="is-{{ $key }}"><strong>{{ $counts[$key] }}</strong> {{ $label }}</li>@endforeach
                </ul>

                <div class="wca-table-wrap">
                    <table class="wca-table is-stack is-compact">
                        <thead><tr><th>Row</th><th>Student ID</th><th>Name</th><th>Result</th><th>Why</th></tr></thead>
                        <tbody>
                        @foreach ($rows as $r)
                            <tr>
                                <td data-label="Row">{{ $r['row'] }}</td>
                                <td data-label="Student ID">{{ $r['id'] !== '' ? $r['id'] : '—' }}</td>
                                <td data-label="Name">{{ $r['name'] ?? '—' }}</td>
                                <td data-label="Result"><span class="wca-pill is-up-{{ $r['status'] }}">{{ $labels[$r['status']] }}</span></td>
                                <td data-label="Why">{{ $r['reason'] ?? '' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="wca-actions">
                    <button type="button" class="wca-btn is-danger" data-modal-open="wca-confirm-upload" @disabled($counts['will'] === 0)>Confirm: dismiss {{ $counts['will'] }} student(s)</button>
                    <form method="POST" action="{{ route('candidacy.cgs.dismissal-upload.discard') }}" class="wca-inline">@csrf<button type="submit" class="wca-btn">Discard this preview</button></form>
                </div>
                @if ($counts['will'] === 0)<p class="wca-muted wca-small">There is nothing to apply, so Confirm is switched off.</p>@endif
            </section>

            <form method="POST" action="{{ route('candidacy.cgs.dismissal-upload.confirm') }}" id="wca-upload-confirm-form">@csrf</form>
            <div class="wca-modal wca-confirm" id="wca-confirm-upload" role="alertdialog" aria-modal="true" aria-labelledby="wca-c-up-t" aria-describedby="wca-c-up-d" hidden>
                <div class="wca-modal-box">
                    <h3 id="wca-c-up-t">Dismiss {{ $counts['will'] }} student(s)?</h3>
                    <div id="wca-c-up-d">
                        <p><strong>This cannot be undone here.</strong></p>
                        <ul class="wca-consequences">
                            <li>{{ $counts['will'] }} student(s) are marked as dismissed and leave the Dismissal List.</li>
                            <li>Their reminders stop, and a workstation they hold goes into the termination process.</li>
                            <li>Each student is sent an e-mail and a bell notice at once.</li>
                            <li>The other {{ $counts['total'] - $counts['will'] }} row(s) are skipped. Every student is checked again when you confirm.</li>
                        </ul>
                    </div>
                    <div class="wca-actions">
                        <button type="button" class="wca-btn" id="wca-confirm-discard" data-confirm-close>Discard</button>
                        <button type="button" class="wca-btn is-danger" id="wca-confirm-final" data-confirm-submit="wca-upload-confirm-form">Confirm and dismiss</button>
                    </div>
                </div>
            </div>
        @endif

        <section class="wca-card" aria-labelledby="wca-up-3">
            <h3 id="wca-up-3">Last 10 uploads</h3>
            @if ($recent->isEmpty())
                <p class="wca-muted">No file has been uploaded yet.</p>
            @else
                <div class="wca-table-wrap">
                    <table class="wca-table is-stack is-compact">
                        <thead><tr><th>When</th><th>By</th><th>File</th><th class="wca-num">Rows</th><th class="wca-num">Dismissed</th><th class="wca-num">Skipped</th></tr></thead>
                        <tbody>
                        @foreach ($recent as $i)
                            <tr>
                                <td class="wca-nowrap" data-label="When">{{ $i->uploaded_at->timezone('Asia/Kuala_Lumpur')->format('j M Y, H:i') }}</td>
                                <td data-label="By">{{ $i->uploader?->name ?? '—' }}</td>
                                <td data-label="File">{{ $i->file_name }}</td>
                                <td class="wca-num" data-label="Rows">{{ $i->rows_total }}</td>
                                <td class="wca-num" data-label="Dismissed">{{ $i->dismissed }}</td>
                                <td class="wca-num" data-label="Skipped">{{ $i->skipped }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
@endsection
