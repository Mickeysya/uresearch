@extends('core::layouts.app')

@section('title', 'My Drafts')

{{--
    The student's saved drafts -- GA Extension, Certification, GRA
    Application and GA Application -- DraftsController. A draft is never on
    a stage, so it is in no queue and no one but its student can see it.
    Delete removes it completely, attached files included.
--}}

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="My Drafts"
        subtitle="Requests you have started but not submitted yet. Nobody else can see these until you submit them." />

    @if ($drafts->isEmpty())
        <div class="empty-state">
            You have no saved drafts. Use <b>Save draft</b> on the GA Extension, Certification Letter, GRA Application or GA Application form to keep a half-finished request.
        </div>
    @else
        <div class="card card-wide">
            <div class="table-scroll">
                <table class="spec-table">
                    <thead>
                        <tr>
                            <th>Request</th>
                            <th>Last saved</th>
                            <th>Files</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($drafts as $row)
                            <tr data-draft="{{ $row['application']->id }}">
                                <td>
                                    <b>{{ $row['module'] }}</b>
                                    <div class="queue-meta">{{ $row['summary'] }}</div>
                                </td>
                                <td style="white-space: nowrap;">{{ $row['saved']->format('j M Y, g:ia') }}</td>
                                <td>{{ $row['files'] }}</td>
                                <td style="white-space: nowrap; text-align: right;">
                                    <a href="{{ $row['continue'] }}" class="btn">Continue</a>
                                    <form method="POST" action="{{ $row['delete'] }}" style="display: inline;"
                                          data-confirm="Delete this draft and its attached files? This can't be undone.">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn-reject" aria-label="Delete draft: {{ $row['module'] }}, last saved {{ $row['saved']->format('j M Y, g:ia') }}">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
@endsection

@push('scripts')
    <script @cspNonce>
        // Deleting a draft also deletes its uploaded files, so ask first.
        document.querySelectorAll('form[data-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (! window.confirm(form.dataset.confirm)) event.preventDefault();
            });
        });
    </script>
@endpush
