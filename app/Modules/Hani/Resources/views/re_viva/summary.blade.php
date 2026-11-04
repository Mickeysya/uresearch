@extends('core::layouts.app')

@section('title', 'Summary of Corrections')

@section('content')
{{-- The student's digital Summary of Corrections. No scripts: blank rows
     are offered for each examiner and empty ones are dropped on save.
     Examiners are numbered, never named. --}}
@php($blank = max(3, 6 - count($summary->rows ?? [])))
<div class="card-container-inline">
    <x-core::page-header
        title="Summary of Corrections"
        subtitle="List every comment from each examiner's report, the action you took and the pages you updated. The system checks it against your corrected thesis and prepares the PDF for your supervisor to endorse.">
        <a href="{{ route('reviva.mine') }}" class="btn">My Re-viva</a>
    </x-core::page-header>

    @if ($result)
        <div class="card card-wide">
            <h3>Checks</h3>
            <ul class="reviva-summary-checks">
                @foreach ($result['checks'] as $check)
                    <li>
                        <span class="status-badge {{ ['pass' => 'approved', 'problem' => 'rejected', 'unchecked' => 'pending'][$check['status']] }}">
                            {{ ['pass' => 'Pass', 'problem' => 'Problem', 'unchecked' => 'Not checked'][$check['status']] }}
                        </span>
                        {{ $check['text'] }}
                    </li>
                @endforeach
            </ul>
            @if ($summary->submitted_at)
                <p class="message-success">Ready since {{ $summary->submitted_at->format('j M Y') }}. <a href="{{ route('reviva.summary.pdf') }}">Download the Summary of Corrections (PDF)</a>. CGS can attach it when they log your corrected thesis.</p>
            @endif
        </div>
    @endif

    <div class="card card-wide">
        <form method="POST" action="{{ route('reviva.summary.save') }}" enctype="multipart/form-data">
            @csrf
            @if ($window)
                <p class="fstep-hint">Your corrected thesis is due to CGS by {{ $window->closes?->format('j M Y') }}.</p>
            @endif

            <label for="thesis">Corrected thesis (PDF){{ $summary->thesis_name ? ': '.$summary->thesis_name.' on file, '.($summary->thesis_pages ? $summary->thesis_pages.' pages' : 'pages not read') : '' }}</label>
            <input type="file" name="thesis" id="thesis" accept="application/pdf,.pdf">
            <p class="fstep-hint">Used only to count the pages; send the thesis itself to CGS with this summary.</p>
            @error('thesis') <p class="field-error">{{ $message }}</p> @enderror

            @php($i = 0)
            @foreach (\App\Modules\Hani\Models\ReVivaSummary::EXAMINERS as $n => $label)
                <h3>{{ $label }}</h3>
                <div class="table-scroll">
                    <table class="data-table">
                        <thead><tr><th>No.</th><th>Examiner's comment (verbatim)</th><th>Action taken</th><th>Updated pages</th></tr></thead>
                        <tbody>
                            @foreach (array_merge($summary->rowsFor($n), array_fill(0, $blank, ['comment' => '', 'action' => '', 'pages' => ''])) as $k => $row)
                                <tr>
                                    <td>{{ $k + 1 }}<input type="hidden" name="rows[{{ $i }}][examiner]" value="{{ $n }}"></td>
                                    <td><textarea name="rows[{{ $i }}][comment]" rows="2" aria-label="{{ $label }} comment {{ $k + 1 }}">{{ $row['comment'] }}</textarea></td>
                                    <td><textarea name="rows[{{ $i }}][action]" rows="2" aria-label="{{ $label }} action {{ $k + 1 }}">{{ $row['action'] }}</textarea></td>
                                    <td><input type="text" name="rows[{{ $i }}][pages]" value="{{ $row['pages'] }}" placeholder="12-14" aria-label="{{ $label }} pages {{ $k + 1 }}"></td>
                                </tr>
                                @php($i++)
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
            @error('rows') <p class="field-error">{{ $message }}</p> @enderror

            <p class="fstep-hint">Need more rows? Save, and more blank rows appear.</p>
            <button type="submit" name="intent" value="save">Save</button>
            <button type="submit" name="intent" value="submit">Check and prepare the PDF</button>
        </form>
    </div>
</div>
@endsection
