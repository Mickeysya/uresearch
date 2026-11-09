{{-- The pool's filter bar. $route (route name), $type, $departments and $filters
     required. $stateLabel names the state filter; $emailFilter adds CGS's email
     upkeep filter. --}}
@php($id = $route === 'examiner-admin.index' ? 'f' : 'pool')
<form method="GET" action="{{ route($route) }}" class="examiner-filters">
    {{-- Filtering stays on the list you are looking at. --}}
    <input type="hidden" name="type" value="{{ $type }}">
    <div class="field">
        <label for="{{ $id }}-department">Department</label>
        <select name="department" id="{{ $id }}-department" data-autosubmit>
            <option value="">All Departments</option>
            @foreach ($departments as $dept)
                <option value="{{ $dept }}" @selected(($filters['department'] ?? null) === $dept)>{{ $dept }}</option>
            @endforeach
        </select>
    </div>
    <div class="field">
        <label for="{{ $id }}-status">{{ $stateLabel ?? 'Status' }}</label>
        <select name="status" id="{{ $id }}-status" data-autosubmit>
            <option value="">All {{ $stateLabel ?? 'Status' }}</option>
            @foreach ([
                \App\Modules\Hani\Models\Examiner::STATE_AVAILABLE => 'Available',
                \App\Modules\Hani\Models\Examiner::STATE_ON_GAP => 'On Gap',
                \App\Modules\Hani\Models\Examiner::STATE_ASSIGNED => 'Assigned',
                \App\Modules\Hani\Models\Examiner::STATE_UNAVAILABLE => 'Unavailable',
            ] as $value => $label)
                <option value="{{ $value }}" @selected(($filters['status'] ?? null) === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    @if ($emailFilter ?? false)
        <div class="field">
            <label for="{{ $id }}-email">Email</label>
            <select name="email" id="{{ $id }}-email" data-autosubmit>
                <option value="">All emails</option>
                <option value="problem" @selected(($filters['email'] ?? null) === 'problem')>Missing or invalid emails</option>
                <option value="stale" @selected(($filters['email'] ?? null) === 'stale')>Emails not confirmed in {{ config('hani.examiner_contact.stale_months') }} months</option>
            </select>
        </div>
    @endif
    <div class="field search-field">
        <label for="{{ $id }}-search">Search</label>
        <input type="text" name="search" id="{{ $id }}-search" placeholder="Search by name, department..."
               value="{{ $filters['search'] ?? '' }}">
    </div>
    <button type="submit">Filter</button>
    @if (array_filter(\Illuminate\Support\Arr::except($filters, 'type')))
        <a href="{{ route($route, array_filter(['type' => $type])) }}" class="clear-link">Clear filters</a>
    @endif
</form>
