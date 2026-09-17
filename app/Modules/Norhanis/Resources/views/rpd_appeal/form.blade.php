@extends('core::layouts.app')

@section('title', 'RPD Extension Appeal')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>RPD Extension Appeal</h2>
        <div class="card-divider"></div>

        @if (! $candidacy)
            <div class="empty-state">
                <p>You have no RPD candidacy on record yet.</p>
                <p class="queue-meta">CGS registers your candidature and its deadline. Contact them before filing an appeal.</p>
            </div>
        @elseif ($openAppeal)
            <div class="empty-state">
                <p>Appeal #{{ $openAppeal->id }} is already under review.</p>
                <p class="queue-meta">Wait for its outcome before filing another.</p>
                <a href="{{ route('applications.show', $openAppeal) }}" class="btn-secondary">Track this appeal</a>
            </div>
        @elseif (! $candidacy->canAppeal())
            <div class="empty-state">
                <p>
                    @if ($candidacy->extensionMonthsRemaining() === 0)
                        You have used the full {{ \App\Modules\Norhanis\Models\Candidacy::MAX_EXTENSION_MONTHS }}-month extension ceiling.
                    @else
                        Your candidacy is {{ $candidacy->status }} and can no longer be appealed.
                    @endif
                </p>
                <p class="queue-meta"><a href="{{ route('candidacies.mine') }}">View your candidacy</a></p>
            </div>
        @else
            {{-- The facts the student is appealing against, stated before the
                 form asks anything. An appeal form that does not show the
                 current deadline makes the student go and look it up. --}}
            <dl class="rpd-facts">
                <div>
                    <dt>Current RPD deadline</dt>
                    <dd class="tone-{{ $candidacy->tone() }}">{{ $candidacy->rpd_deadline->format('j M Y') }}</dd>
                </div>
                <div>
                    <dt>Time remaining</dt>
                    <dd>{{ $candidacy->isOverdue()
                        ? abs($candidacy->daysRemaining()).' days overdue'
                        : $candidacy->daysRemaining().' days' }}</dd>
                </div>
                <div>
                    <dt>Extension left</dt>
                    <dd>{{ $candidacy->extensionMonthsRemaining() }} of {{ \App\Modules\Norhanis\Models\Candidacy::MAX_EXTENSION_MONTHS }} months</dd>
                </div>
            </dl>

            <form method="POST" action="{{ route('rpd-appeal.store') }}" enctype="multipart/form-data" class="app-form" data-stepper>
                @csrf

                <fieldset class="fstep" data-label="Extension">
                <p class="fstep-hint">How much longer you need. The ceiling is
                   {{ \App\Modules\Norhanis\Models\Candidacy::MAX_EXTENSION_MONTHS }} months across every appeal you ever file,
                   and you have {{ $candidacy->extensionMonthsRemaining() }} left.</p>

                <label for="requested_months">Months requested</label>
                <input type="number" name="requested_months" id="requested_months" required
                       min="1" max="{{ $maxMonths }}" step="1"
                       value="{{ old('requested_months') }}"
                       class="@error('requested_months') is-invalid @enderror">
                <p class="field-hint">
                    New deadline would be
                    <b data-rpd-preview data-rpd-from="{{ $candidacy->rpd_deadline->toDateString() }}">—</b>.
                </p>
                @error('requested_months') <p class="field-error">{{ $message }}</p> @enderror
                </fieldset>

                <fieldset class="fstep" data-label="Justification">
                <p class="fstep-hint">This is read by your supervisor, the Chair, CGS and finally the Dean of PGR. Be specific about what delayed the defence and what changes with more time.</p>

                <label for="justification">Justification</label>
                <textarea name="justification" id="justification" rows="7" required
                          class="@error('justification') is-invalid @enderror">{{ old('justification') }}</textarea>
                @error('justification') <p class="field-error">{{ $message }}</p> @enderror
                </fieldset>

                <fieldset class="fstep" data-label="Evidence">
                <p class="fstep-hint">Optional, but an appeal with evidence behind it moves faster.</p>

                <label for="supporting_document">Supporting document <span style="color: var(--text-grey)">(optional)</span></label>
                <input type="file" name="supporting_document" id="supporting_document"
                       class="@error('supporting_document') is-invalid @enderror">
                @error('supporting_document') <p class="field-error">{{ $message }}</p> @enderror
                </fieldset>

                <button type="submit">Submit Appeal</button>
            </form>
        @endif
    </div>
</div>

@include('core::partials.form-stepper')

@if ($candidacy && $candidacy->canAppeal() && ! $openAppeal)
    @push('scripts')
        <script @cspNonce>
            // Live preview of the new deadline. Purely informational -- the
            // real arithmetic is RpdAppealController::grantExtension(), which
            // extends from whatever the deadline is at approval time.
            (function () {
                var input = document.getElementById('requested_months');
                var out = document.querySelector('[data-rpd-preview]');
                if (! input || ! out) return;

                var from = out.dataset.rpdFrom;

                function render() {
                    var months = parseInt(input.value, 10);
                    if (! months || months < 1) { out.textContent = '—'; return; }

                    // Parsed as UTC parts so the displayed date cannot shift by
                    // a day in a timezone west of the server.
                    var parts = from.split('-').map(Number);
                    var d = new Date(Date.UTC(parts[0], parts[1] - 1, parts[2]));
                    var targetMonth = d.getUTCMonth() + months;
                    var end = new Date(Date.UTC(d.getUTCFullYear(), targetMonth + 1, 0));

                    // addMonthsNoOverflow: clamp to the last day of the target
                    // month rather than rolling into the next one.
                    d.setUTCFullYear(d.getUTCFullYear(), targetMonth,
                        Math.min(d.getUTCDate(), end.getUTCDate()));

                    out.textContent = d.toLocaleDateString('en-GB',
                        { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });
                }

                input.addEventListener('input', render);
                render();
            })();
        </script>
    @endpush
@endif
@endsection
