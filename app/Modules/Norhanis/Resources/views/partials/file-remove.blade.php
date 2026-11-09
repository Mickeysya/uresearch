{{--
    A "Remove file" button under every file field on the page, shown once a
    file is picked. A browser's file input has no way to undo a pick except
    choosing another file, so a student who attached the wrong document was
    stuck with it until they submitted.

    Include once, after the form. It fires "change" after clearing, so any
    script already listening (the filename line on Claims, the stepper's
    required check) sees the field go empty.
--}}
<script @cspNonce>
(function () {
    document.querySelectorAll('input[type="file"]').forEach(function (input) {
        // Nureen's multi-file picker has its own remove buttons.
        if (input.dataset.removable || input.hasAttribute('data-doc-upload-input')) return;
        input.dataset.removable = '1';

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn-secondary';
        button.textContent = 'Remove file';
        button.style.marginTop = '8px';
        button.hidden = true;
        button.setAttribute('aria-label', 'Remove the chosen file');
        input.insertAdjacentElement('afterend', button);

        input.addEventListener('change', function () {
            button.hidden = ! (input.files && input.files.length);
        });

        button.addEventListener('click', function () {
            input.value = '';
            input.dispatchEvent(new Event('change', { bubbles: true }));
            input.focus();
        });
    });
})();
</script>
