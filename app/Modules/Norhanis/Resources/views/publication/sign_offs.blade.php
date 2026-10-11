@extends('core::layouts.app')

@section('title', 'Publication Application #' . $application->id . ' Sign-offs')

@section('content')
    @php($signed = $detail->authors->filter->hasSigned()->count())
    @php($total = $detail->authors->count())
    @php($settled = $detail->activeEvaluations->filter->isSatisfied()->count())
    @php($reviewers = $detail->activeEvaluations->count())
    @php($collecting = $application->status === \App\Modules\Core\Models\Application::STATUS_DRAFT)

    <x-core::page-header title="Publication Application #{{ $application->id }}"
                         subtitle="{{ $detail->title_of_paper }}" />

    @if ($errors->any())
        <p class="message-error">{{ $errors->first() }}</p>
    @endif

    <div class="app-item">
        @if ($collecting)
            <p><b>Collecting sign-offs:</b> {{ $signed }} of {{ $total }} authors have signed,
               and {{ $settled }} of {{ $reviewers }} paper evaluations are complete.
               @if ($memo = $detail->memo)
                   The split budget memo {{ $memo->isSigned() ? 'is signed' : 'is not fully signed yet' }}.
               @endif
            </p>
            <p style="color: var(--text-grey);">
                When the last one is in, your application goes to your supervisor by itself.
                You do not need to come back and submit it.
            </p>
        @else
            <p><b>Every sign-off is in</b>, and your application has been submitted.</p>
            <p><a href="{{ route('applications.show', $application) }}" class="btn-secondary">Track the application</a></p>
        @endif
    </div>

    {{-- ---- Authorship Certification ------------------------------------ --}}
    <div class="app-item">
        <h4>Authorship Certification</h4>

        @if ($form = $generated->get('Authorship Certification Form'))
            <p><a href="{{ route('documents.show', $form) }}" class="btn-secondary">Download the signed form (PDF)</a></p>
        @endif

        <table class="recent-activity-table" style="margin-top: 12px;">
            <tr>
                <th>#</th>
                <th>Author</th>
                <th>Email</th>
                <th>Status</th>
                <th></th>
            </tr>
            @foreach ($detail->authors as $author)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $author->author_name }}</td>
                    <td>{{ $author->email ?? '—' }}</td>
                    <td>
                        @if ($author->hasSigned())
                            Signed {{ $author->signed_at->format('j M Y, g:ia') }}
                        @else
                            Waiting
                            @if ($author->invited_at)
                                <span style="color: var(--text-grey);">(emailed {{ $author->invited_at->format('j M Y, g:ia') }})</span>
                            @endif
                        @endif
                    </td>
                    <td>
                        @if ($collecting && ! $author->hasSigned() && $author->email)
                            <form method="POST" action="{{ route('publication.authors.remind', [$application, $author]) }}">
                                @csrf
                                <button type="submit" class="btn-secondary">Send reminder</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    </div>

    {{-- ---- Paper Evaluation -------------------------------------------- --}}
    @foreach ($detail->activeEvaluations as $evaluation)
        <div class="app-item">
            <h4>Paper Evaluation, Reviewer {{ $evaluation->position }}</h4>
            <p><b>{{ $evaluation->reviewer_name }}</b> &middot; {{ $evaluation->reviewer_email }}</p>

            @if ($evaluation->isSubmitted())
                <p>
                    <b>{{ $evaluation->recommendationLabel() }}</b>,
                    signed {{ $evaluation->signed_at->format('j M Y, g:ia') }}
                </p>
                <p>
                    @foreach (\App\Modules\Norhanis\Models\PublicationEvaluation::CRITERIA as $key => [$heading])
                        {{ $heading }}: <b>{{ $evaluation->{$key.'_rating'} }}</b>@if (! $loop->last) &middot; @endif
                    @endforeach
                </p>

                @foreach (\App\Modules\Norhanis\Models\PublicationEvaluation::CRITERIA as $key => [$heading])
                    @if ($evaluation->{$key.'_comment'})
                        <p><b>{{ $heading }} comment:</b> {{ $evaluation->{$key.'_comment'} }}</p>
                    @endif
                @endforeach
                @if ($evaluation->additional_comments)
                    <p><b>Additional comments:</b> {{ $evaluation->additional_comments }}</p>
                @endif

                @if ($sheet = $generated->get($evaluation->sheetLabel()))
                    <p><a href="{{ route('documents.show', $sheet) }}" class="btn-secondary">Download the signed sheet (PDF)</a></p>
                @endif

                @if ($evaluation->responded_at)
                    <p><b>Your response</b> ({{ $evaluation->responded_at->format('j M Y, g:ia') }}): {{ $evaluation->response }}</p>
                @elseif ($collecting && $evaluation->needsResponse())
                    <form method="POST" action="{{ route('publication.evaluations.respond', [$application, $evaluation]) }}"
                          enctype="multipart/form-data" class="app-form">
                        @csrf
                        <p class="message-error">This reviewer asked for corrections. Reply to the comments and upload the corrected paper to continue.</p>

                        <label for="response_{{ $evaluation->id }}">Your response to the comments</label>
                        <textarea name="response" id="response_{{ $evaluation->id }}" rows="4" required>{{ old('response') }}</textarea>

                        <label for="corrected_paper_{{ $evaluation->id }}">Corrected paper</label>
                        <input type="file" name="corrected_paper" id="corrected_paper_{{ $evaluation->id }}" required>

                        <button type="submit" style="margin-top: 12px;">Send response</button>
                    </form>
                @endif

                @if ($evaluation->recommendation === \App\Modules\Norhanis\Models\PublicationEvaluation::REJECTED)
                    <p class="message-error">
                        The application cannot continue with a rejected evaluation. Revise the paper, then ask for a new
                        evaluation below. This rejection stays on the record.
                    </p>
                @endif
            @elseif ($evaluation->isDeclined())
                <p class="message-error">
                    Unable to review ({{ $evaluation->declined_at->format('j M Y, g:ia') }}): {{ $evaluation->decline_reason }}
                    Choose another reviewer below.
                </p>
            @else
                <p>
                    Waiting
                    @if ($evaluation->invited_at)
                        <span style="color: var(--text-grey);">(emailed {{ $evaluation->invited_at->format('j M Y, g:ia') }})</span>
                    @endif
                </p>
                @if ($collecting)
                    <form method="POST" action="{{ route('publication.evaluations.remind', [$application, $evaluation]) }}">
                        @csrf
                        <button type="submit" class="btn-secondary">Send reminder</button>
                    </form>
                @endif
            @endif

            @if ($collecting && $evaluation->canBeReplaced())
                <details style="margin-top: 12px;" @if ($evaluation->isDeclined() || $evaluation->isSubmitted()) open @endif>
                    <summary>Ask a different reviewer</summary>
                    <form method="POST" action="{{ route('publication.evaluations.replace', [$application, $evaluation]) }}" class="app-form">
                        @csrf
                        <label for="reviewer_name_{{ $evaluation->id }}">Name</label>
                        <input type="text" name="reviewer_name" id="reviewer_name_{{ $evaluation->id }}" required maxlength="150">

                        <label for="reviewer_email_{{ $evaluation->id }}">UTP email</label>
                        <input type="email" name="reviewer_email" id="reviewer_email_{{ $evaluation->id }}" required>

                        <button type="submit" class="btn-secondary" style="margin-top: 12px;">Send the request</button>
                    </form>
                </details>
            @endif
        </div>
    @endforeach

    {{-- ---- Split budget memo ------------------------------------------- --}}
    @if ($memo = $detail->memo)
        <div class="app-item">
            <h4>Split Budget Memo</h4>

            @if ($memoPdf = $generated->get('Approved Memo for Utilizing Grant') ?? $generated->get('Memo Request Split Budget'))
                <p><a href="{{ route('documents.show', $memoPdf) }}" class="btn-secondary">Download the memo (PDF)</a></p>
            @endif

            @include('norhanis::publication._memo_split')

            <table class="recent-activity-table" style="margin-top: 12px;">
                <tr><th></th><th>Signer</th><th>Email</th><th>Status</th></tr>
                @foreach (\App\Modules\Norhanis\Models\PublicationMemo::SIGNERS as $signer => $line)
                    <tr>
                        <td>{{ $line }}</td>
                        <td>{{ $memo->{$signer.'_name'} }}</td>
                        <td>{{ $memo->{$signer.'_email'} }}</td>
                        <td>
                            @if ($memo->hasSigned($signer))
                                Signed {{ $memo->{$signer.'_signed_at'}->format('j M Y, g:ia') }}
                            @elseif ($memo->awaiting() === $signer)
                                Waiting
                                @if ($memo->{$signer.'_invited_at'})
                                    <span style="color: var(--text-grey);">(emailed {{ $memo->{$signer.'_invited_at'}->format('j M Y, g:ia') }})</span>
                                @endif
                            @else
                                Not yet their turn
                            @endif
                        </td>
                    </tr>
                @endforeach
                <tr>
                    <td>To</td>
                    <td colspan="2">Senior Director, Centre for Graduate Studies</td>
                    <td>Approves it with the application</td>
                </tr>
            </table>

            @if ($memo->isDeclined())
                <p class="message-error" style="margin-top: 12px;">
                    {{ $memo->{$memo->declined_by.'_name'} }} sent the memo back ({{ $memo->declined_at->format('j M Y, g:ia') }}):
                    {{ $memo->decline_reason }} Correct it below to send it for signature again.
                </p>
            @elseif ($collecting && $memo->awaiting())
                <form method="POST" action="{{ route('publication.memo.remind', $application) }}" style="margin-top: 12px;">
                    @csrf
                    <button type="submit" class="btn-secondary">Send reminder</button>
                </form>
            @endif

            @if ($collecting)
                <details style="margin-top: 12px;" @if ($memo->isDeclined()) open @endif>
                    <summary>Correct the grant or centre details</summary>
                    <form method="POST" action="{{ route('publication.memo.revise', $application) }}" class="app-form">
                        @csrf
                        <p class="queue-meta">Saving a correction clears any signature already on the memo and sends it to your supervisor again.</p>

                        @foreach ([
                            'grant_name' => ['Grant name', 'text'],
                            'grant_cost_centre' => ['Grant cost centre', 'text'],
                            'centre_name' => ['Research centre that holds the grant', 'text'],
                            'head_name' => ['Head of that centre', 'text'],
                            'head_email' => ["Centre head's email", 'email'],
                        ] as $field => [$label, $type])
                            <label for="memo_{{ $field }}">{{ $label }}</label>
                            <input type="{{ $type }}" name="{{ $field }}" id="memo_{{ $field }}" required
                                   value="{{ old($field, $memo->{$field}) }}">
                        @endforeach

                        <button type="submit" class="btn-secondary" style="margin-top: 12px;">Save and send for signature</button>
                    </form>
                </details>
            @endif
        </div>
    @endif

    @if ($replaced->isNotEmpty())
        <div class="app-item">
            <h4>Earlier reviewers</h4>
            @foreach ($replaced as $evaluation)
                <p>
                    Reviewer {{ $evaluation->position }}: {{ $evaluation->reviewer_name }},
                    @if ($evaluation->isSubmitted()) {{ $evaluation->recommendationLabel() }}
                    @elseif ($evaluation->isDeclined()) unable to review
                    @else no reply
                    @endif
                    (replaced {{ $evaluation->replaced_at->format('j M Y') }})
                </p>
            @endforeach
        </div>
    @endif
@endsection
