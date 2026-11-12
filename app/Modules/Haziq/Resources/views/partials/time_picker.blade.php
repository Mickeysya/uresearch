{{--
    Hour (1-12, select) / minute (free entry, 0-59) / AM-PM (select) --
    not a raw <input type="time">, which renders as 24-hour on some
    browsers/locales. Confirmed explicitly 2026-11-10: "set timing tu cam
    tak user friendly je, buat lah am pm features terima 1-12 tu." Minute
    is a plain number input, not a quarter-step select -- confirmed the
    same day: "yang minit tu tak kisah lah nak tulis berapa pun."

    Required params: $name (field name prefix, e.g. "interview_start"),
    $label. Optional: $idSuffix, for a loop where more than one of these
    renders on the same page (e.g. the queue, one per application).
--}}
@php($idSuffix = $idSuffix ?? '')
<label for="{{ $name }}_hour{{ $idSuffix }}">{{ $label }}</label>
<span style="display: inline-flex; gap: 4px; align-items: center;">
    <select name="{{ $name }}_hour" id="{{ $name }}_hour{{ $idSuffix }}" required>
        @foreach (range(1, 12) as $hour)
            <option value="{{ $hour }}">{{ $hour }}</option>
        @endforeach
    </select>
    <span aria-hidden="true">:</span>
    <input type="number" name="{{ $name }}_minute" min="0" max="59" value="0" step="1"
           aria-label="{{ $label }} minute" style="width: 4em;" required>
    <select name="{{ $name }}_meridiem" aria-label="{{ $label }} AM or PM" required>
        <option value="AM">AM</option>
        <option value="PM">PM</option>
    </select>
</span>
