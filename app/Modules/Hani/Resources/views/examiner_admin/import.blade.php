@extends('core::layouts.app')

@section('title', 'Import Examiner Pool')

@section('content')
{{-- Page-scoped, tokens only, so it follows the dark theme. --}}
<style>
    .import-badge {
        display: inline-block; padding: 2px 9px; border-radius: 999px;
        font-size: var(--text-xs); font-weight: 600; white-space: nowrap;
    }
    .import-badge.is-new, .import-badge.is-unchanged { background: var(--success-bg); color: var(--success-fg); }
    .import-badge.is-update { background: var(--info-bg); color: var(--info-fg); }
    .import-badge.is-duplicate, .import-badge.is-missing-email { background: var(--warning-bg); color: var(--warning-fg); }
    .import-badge.is-rejected { background: var(--danger-bg); color: var(--danger-fg); }
    .import-counts { display: flex; flex-wrap: wrap; gap: 8px; margin: 4px 0 16px; }
    .import-table td { vertical-align: top; font-size: var(--text-sm); }
    .import-table .import-notes { margin: 0; padding-left: 16px; color: var(--text-grey); font-size: var(--text-xs); }
    .import-table .import-mapped { display: block; color: var(--text-grey); font-size: var(--text-xs); }
    .import-wrap { overflow-x: auto; }
</style>

<div class="card-container-inline">
    <x-core::page-header
        title="Import Examiner Pool"
        subtitle="Upload CGS's pool workbook (Internal Examiner / External Examiner sheets) or the UResearch template. You will see every row before anything changes.">
        <a href="{{ route('examiner-import.template') }}" class="btn-secondary">Download the UResearch template</a>
        @if ($openReviewItems)
            <a href="{{ route('examiner-import.review') }}" class="btn-secondary">Review list ({{ $openReviewItems }})</a>
        @endif
    </x-core::page-header>

    @if (! $batch)
        <div class="card card-wide">
            <form method="POST" action="{{ route('examiner-import.store') }}" enctype="multipart/form-data" class="app-form">
                @csrf
                <label for="file">Pool file <span style="color: var(--text-grey)">(.xlsx or .csv, up to {{ (int) (config('hani.import.max_kb') / 1024) }} MB)</span></label>
                <input type="file" name="file" id="file" required accept=".xlsx,.csv"
                       class="@error('file') is-invalid @enderror">
                @error('file') <p class="field-error">{{ $message }}</p> @enderror

                <p style="color: var(--text-grey); font-size: var(--text-sm);">
                    The layout is recognised from the column headings. Nothing is saved until you confirm the preview,
                    examiners are never deleted, and re-importing the same file changes nothing.
                </p>

                <button type="submit">Preview the import</button>
            </form>
        </div>
    @else
        <div class="card card-wide" style="max-width: none;">
            <h3 style="margin-top: 0;">{{ $batch->filename }}</h3>
            <p style="color: var(--text-grey);">
                Read as {{ $batch->layout === 'cgs' ? 'CGS\'s current layout' : 'the UResearch template' }}, {{ count($rows) }} {{ Str::plural('row', count($rows)) }}.
                Checked against the pool as it stands now.
            </p>

            <div class="import-counts">
                @foreach (\App\Modules\Hani\Support\Import\PoolImporter::LABELS as $status => $label)
                    @if ($counts[$status])
                        <span class="import-badge is-{{ $status }}">{{ $label }}: {{ $counts[$status] }}</span>
                    @endif
                @endforeach
                @if ($missingEmail)
                    <span class="import-badge is-missing-email">Missing email: {{ $missingEmail }}</span>
                @endif
            </div>

            @foreach ($batch->messages ?? [] as $message)
                <p class="message-warning">{{ $message }}</p>
            @endforeach

            <div class="import-wrap">
                <table class="data-table import-table">
                    <thead>
                        <tr><th>Row</th><th>Examiner</th><th>Type</th><th>Department</th><th>Status</th><th>Notes</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $i => $row)
                            @php($result = $classified[$i])
                            <tr>
                                <td>{{ $row->source }}</td>
                                <td>
                                    {{ $row->get('name') ?? '—' }}
                                    <span class="import-mapped">{{ $row->get('email') ?? 'no email' }}</span>
                                </td>
                                <td>{{ ucfirst($row->type() ?? '—') }}</td>
                                <td>
                                    {{ $row->departmentAsWritten ?? '—' }}
                                    @if ($row->get('department') && $row->get('department') !== $row->departmentAsWritten)
                                        <span class="import-mapped">→ {{ $row->get('department') }}</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="import-badge is-{{ $result['status'] }}">{{ \App\Modules\Hani\Support\Import\PoolImporter::LABELS[$result['status']] }}</span>
                                    @if ($result['missing_email'] && $result['status'] !== 'rejected')
                                        <span class="import-badge is-missing-email">Missing email</span>
                                    @endif
                                </td>
                                <td>
                                    @php($notes = array_merge($result['messages'], $result['status'] === 'rejected' ? [] : $row->warnings))
                                    @if ($notes)
                                        <ul class="import-notes">
                                            @foreach ($notes as $note)<li>{{ $note }}</li>@endforeach
                                        </ul>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <form method="POST" action="{{ route('examiner-import.confirm', $batch) }}" style="margin-top: 16px;">
                @csrf
                <p style="color: var(--text-grey); font-size: var(--text-sm);">
                    Confirming adds the New and Possible duplicate rows, applies the Updates, and skips Rejected rows.
                </p>
                <div class="decision-row">
                    <a href="{{ route('examiner-import.create') }}" class="btn-secondary">Upload a different file</a>
                    <button type="submit">Confirm import</button>
                </div>
            </form>
        </div>
    @endif
</div>
@endsection
