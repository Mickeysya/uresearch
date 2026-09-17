{{--
    Things standing between this Chair and work they are about to try.

    Declared by the modules through ProvidesDashboardAlerts, never by Core:
    the rule that a hardbound approval needs a signature on file, and the
    model that answers it, both belong to Jason's folder.

    Nothing renders when nothing is blocking, which is the normal case. An
    alert strip that is always on is one nobody reads.
--}}
@if (! empty($alerts))
    <div class="chair-alerts">
        @foreach ($alerts as $alert)
            <div class="chair-alert tone-{{ $alert['tone'] }}">
                <div class="chair-alert-text">
                    <b>{{ $alert['title'] }}</b>
                    <span>{{ $alert['body'] }}</span>
                </div>

                @isset ($alert['action'])
                    <a href="{{ route($alert['action']['route'], $alert['action']['params'] ?? []) }}"
                       class="btn">{{ $alert['action']['label'] }}</a>
                @endisset
            </div>
        @endforeach
    </div>
@endif
