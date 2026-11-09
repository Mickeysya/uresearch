{{--
    After the form's own Submit button (form-stepper takes the first submit
    button as the form's). Posts the same form to the draft route, without
    the browser's required checks. Nureen's draft script, when her module is
    present, moves it beside Submit on every step and reopens a continued
    draft on the step it was saved on -- the same behaviour as her forms.
--}}
<button type="submit" class="btn-secondary" data-save-draft formnovalidate formaction="{{ $draftSaveUrl }}">Save draft</button>
