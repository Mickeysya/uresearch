@extends('core::layouts.app')

@section('title', 'Publication Application #' . $application->id . ' Sign-offs')

@section('content')
    @php($signed = $detail->authors->filter->hasSigned()->count())
    @php($total = $detail->authors->count())
    @php($collecting = $application->status === \App\Modules\Core\Models\Application::STATUS_DRAFT)

    <x-core::page-header title="Publication Application #{{ $application->id }}"
                         subtitle="{{ $detail->title_of_paper }}" />

    <div class="app-item">
        <h4>Authorship Certification</h4>

        @if ($collecting)
            <p><b>{{ $signed }} of {{ $total }} authors have signed.</b></p>
            <p style="color: var(--text-grey);">
                Each author was emailed a personal link to sign. When the last one signs, your application goes to
                your supervisor by itself. You do not need to come back and submit it.
            </p>
        @else
            <p><b>All {{ $total }} authors have signed</b>, and your application has been submitted.</p>
            <p>
                @if ($form)
                    <a href="{{ route('documents.show', $form) }}" class="btn-secondary">Download the signed form (PDF)</a>
                @endif
                <a href="{{ route('applications.show', $application) }}" class="btn-secondary">Track the application</a>
            </p>
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
@endsection
