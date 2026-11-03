@extends('core::layouts.app')

@section('title', 'Examiner: '.$examiner->name)

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        :title="$examiner->name"
        :subtitle="$examiner->typeLabel().' examiner, '.$examiner->department.($examiner->institution ? ', '.$examiner->institution : '').'. '.$examiner->stateLabel().'.'">
        @if ($canEditAll)
            <a href="{{ route('examiner-admin.edit', $examiner) }}" class="btn-secondary">Edit examiner</a>
        @endif
        @if (auth()->user()->role === \App\Modules\Core\Support\Role::SENIOR_EXEC_CGS || auth()->user()->role === \App\Modules\Core\Support\Role::NON_EXEC_CGS)
            <a href="{{ route('examiner-admin.index') }}" class="btn-secondary">Back to the pool</a>
        @endif
    </x-core::page-header>

    <div class="card card-wide" id="contact">
        <h3>Contact</h3>
        <table class="data-table">
            <tbody>
                <tr>
                    <th scope="row">Email</th>
                    <td>
                        {{ $examiner->email ?? 'No email on record' }}
                        @if ($examiner->needsEmail())
                            <span class="status-badge pending">Email needed</span>
                        @endif
                    </td>
                </tr>
            </tbody>
        </table>

        @if ($canEditEmail)
            <p><a href="{{ route('examiner-admin.email.edit', $examiner) }}" class="btn">Update email</a></p>
        @endif
    </div>

    <div class="card card-wide">
        <h3>Contact history</h3>
        @if ($examiner->contactHistory->isEmpty())
            <p class="fstep-hint">No email changes recorded yet.</p>
        @else
            <table class="data-table">
                <thead>
                    <tr><th>When</th><th>What</th><th>Old email</th><th>New email</th><th>Reason</th><th>By</th></tr>
                </thead>
                <tbody>
                    @foreach ($examiner->contactHistory as $line)
                        <tr>
                            <td>{{ $line->created_at->format('j M Y, g:i a') }}</td>
                            <td>{{ $line->sourceLabel() }}</td>
                            <td>{{ $line->isChange() ? ($line->old_email ?? 'None') : '' }}</td>
                            <td>{{ $line->isChange() ? ($line->new_email ?? 'None') : $line->new_email }}</td>
                            <td>{{ $line->reason }}</td>
                            <td>{{ $line->changedBy?->name ?? 'System' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="card card-wide">
        <h3>Record</h3>
        <table class="data-table">
            <tbody>
                <tr><th scope="row">Department</th><td>{{ $examiner->department }}</td></tr>
                @if ($examiner->faculty)<tr><th scope="row">Faculty</th><td>{{ $examiner->faculty }}</td></tr>@endif
                @if ($examiner->isExternal())
                    <tr><th scope="row">University / Industry</th><td>{{ $examiner->institution }}</td></tr>
                    @if ($examiner->faculty_approval)<tr><th scope="row">Faculty approval</th><td>{{ $examiner->faculty_approval }}</td></tr>@endif
                @endif
                @if ($examiner->expertise)<tr><th scope="row">Area of expertise</th><td>{{ $examiner->expertise }}</td></tr>@endif
                @if ($examiner->expertiseKeywords->isNotEmpty())
                    <tr><th scope="row">Keywords</th><td>{{ $examiner->expertiseKeywords->pluck('keyword')->implode(', ') }}</td></tr>
                @endif
                <tr><th scope="row">Availability</th><td>{{ $examiner->stateLabel() }}@if ($reason = $examiner->ineligibilityReason()). {{ $reason }}@endif</td></tr>
            </tbody>
        </table>
    </div>
</div>
@endsection
