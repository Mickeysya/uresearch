{{--
    A signature box: draw with a mouse or finger, or load an image of a
    signature. Either way it posts ONE field, $name, holding a PNG data: URL,
    which SignatureImage::capture() checks on the server.

    It announces every change with a "signature:change" event (detail.signed),
    so the page around it can gate its own controls. Include once per page.
--}}
<div id="signature-pad">
    <canvas width="600" height="192" aria-label="Signature box"
            style="display: block; width: 100%; max-width: 500px; aspect-ratio: 25 / 8; background: #fff; border: 1px dashed var(--border-strong); border-radius: 8px; touch-action: none; cursor: crosshair;"></canvas>
    <input type="hidden" name="{{ $name }}">

    <p style="display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px;">
        <button type="button" class="btn-secondary" data-signature-upload>Upload an image instead</button>
        <button type="button" class="btn-secondary" data-signature-clear>Clear</button>
    </p>
    <p class="field-hint">Draw in the box, or upload a PNG or JPG image of your signature.</p>
    {{-- No .field-error class until there is an error to show: the form
         stepper opens on the first step holding a .field-error, so an empty
         one here would open every form on the signature step. --}}
    <p data-signature-error hidden></p>
</div>
@error($name) <p class="field-error">{{ $message }}</p> @enderror

<script @cspNonce>
(function () {
    var pad = document.getElementById('signature-pad');
    var canvas = pad.querySelector('canvas');
    var field = pad.querySelector('input[type="hidden"]');
    var ctx = canvas.getContext('2d');
    var drawing = false;
    var signed = false;

    ctx.lineWidth = 2.5;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#111';

    // The canvas is drawn at a fixed 600x192 but shown at whatever width the
    // page gives it, so pointer positions are scaled back into canvas units.
    function point(e) {
        var box = canvas.getBoundingClientRect();
        return {
            x: (e.clientX - box.left) * canvas.width / box.width,
            y: (e.clientY - box.top) * canvas.height / box.height,
        };
    }

    function commit() {
        field.value = signed ? canvas.toDataURL('image/png') : '';
        pad.dispatchEvent(new CustomEvent('signature:change', { bubbles: true, detail: { signed: signed } }));
    }

    canvas.addEventListener('pointerdown', function (e) {
        drawing = true;
        canvas.setPointerCapture(e.pointerId);
        var p = point(e);
        ctx.beginPath();
        ctx.moveTo(p.x, p.y);
        e.preventDefault();
    });

    canvas.addEventListener('pointermove', function (e) {
        if (! drawing) return;
        var p = point(e);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
        signed = true;
    });

    ['pointerup', 'pointercancel'].forEach(function (type) {
        canvas.addEventListener(type, function () {
            if (! drawing) return;
            drawing = false;
            commit();
        });
    });

    pad.querySelector('[data-signature-clear]').addEventListener('click', function () {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        signed = false;
        commit();
    });

    var error = pad.querySelector('[data-signature-error]');

    function showError(message) {
        error.textContent = message;
        error.hidden = ! message;
        error.className = message ? 'field-error' : '';
    }

    // The picker is created on demand and never added to the form, so the
    // image file itself is not uploaded: it is drawn into the box, scaled to
    // fit, and leaves as the same PNG a drawn signature would.
    //
    // Read as a data: URL, not URL.createObjectURL(): the portal's CSP allows
    // img-src 'self' data: only, so a blob: image is blocked and never loads.
    pad.querySelector('[data-signature-upload]').addEventListener('click', function () {
        var picker = document.createElement('input');
        picker.type = 'file';
        picker.accept = 'image/png,image/jpeg';

        picker.addEventListener('change', function () {
            if (! picker.files.length) return;

            var file = picker.files[0];

            if (['image/png', 'image/jpeg'].indexOf(file.type) === -1) {
                showError('Use a PNG or JPG image of your signature.');
                return;
            }

            var reader = new FileReader();
            reader.onload = function () {
                var image = new Image();
                image.onload = function () {
                    var scale = Math.min(canvas.width / image.width, canvas.height / image.height);
                    var w = image.width * scale;
                    var h = image.height * scale;

                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    ctx.drawImage(image, (canvas.width - w) / 2, (canvas.height - h) / 2, w, h);
                    showError('');
                    signed = true;
                    commit();
                };
                image.onerror = function () {
                    showError('That image could not be read. Try another PNG or JPG.');
                };
                image.src = reader.result;
            };
            reader.onerror = function () {
                showError('That image could not be read. Try another PNG or JPG.');
            };
            reader.readAsDataURL(file);
        });

        picker.click();
    });
})();
</script>
