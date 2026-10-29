{{--
    Candidacy-appeal-specific content, shared by two screens:

      - the approver's queue (core::partials.queue passes $details, a
        whole-page map, to avoid an N+1 query per row -- only ever PENDING
        applications, so the status banners below never fire there)
      - the student's own "Track My Application" page
        (core::applications.show, via CandidacyAppealWorkflow::detailView())
        -- only ever one $application, no $details map, so it looks its own
        detail row up directly

    Same $detail either way, so @include('..._sections') never has to know
    which screen it's on.
--}}
@php($detail = isset($details) ? ($details[$application->id] ?? null) : \App\Modules\Chloe\Models\CandidacyAppealDetail::where('application_id', $application->id)->with('candidacy', 'supervisor', 'publications')->first())

@if (! $detail)
    <p style="color: var(--text-grey);">Detail record missing for this application.</p>
@else
    @if (! isset($details))
        {{-- Only on the student's own "Track My Application" page: $details
             is the queue's own marker that this include is on someone else's
             screen, where a link back to their own candidacy makes no sense. --}}
        <p style="margin:0 0 10px;"><a href="{{ route('candidacy.status') }}">&larr; Back to My Candidacy</a></p>
    @endif

    @if ($application->status === \App\Modules\Core\Models\Application::STATUS_RETURNED)
        @php($lastReturn = $application->history->where('decision', 'returned')->last())
        <div class="empty-state" style="text-align:left;">
            <b>Returned at the {{ $lastReturn?->stage_label }} stage: action needed</b>
            @if ($lastReturn?->remarks)
                <p style="margin-top:6px;">"{{ $lastReturn->remarks }}"</p>
            @endif
            <p style="margin-top:10px;">
                <a href="{{ route('candidacy-appeal.edit', $application) }}"><button type="submit">Edit and Resubmit</button></a>
            </p>
        </div>
    @elseif ($application->status === \App\Modules\Core\Models\Application::STATUS_REJECTED)
        <p style="margin-top:6px; color: var(--danger-fg, #D0342C);">
            This appeal was rejected; no further appeals can be submitted for this candidacy.
        </p>
    @elseif ($application->status === \App\Modules\Core\Models\Application::STATUS_APPROVED)
        <div class="empty-state" style="text-align:left;">
            Approved. Your candidacy expiry date is now
            <b>{{ $detail->new_expiry_date?->format('j M Y') ?? $detail->candidacy->candidacy_expiry_date->format('j M Y') }}</b>.
        </div>
    @endif

    @include('chloe::candidacy.appeal._sections', ['detail' => $detail])
@endif
