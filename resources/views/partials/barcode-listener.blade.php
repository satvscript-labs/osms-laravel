{{--
    Global barcode scanner listener — vanilla JS port of the original useBarcode hook.
    Detects fast keystroke bursts ending in Enter (USB/Bluetooth scanners) and calls
    the named global handler with the scanned code.

    Usage: @include('partials.barcode-listener', ['onScan' => 'myHandlerFnName'])
--}}
<script nonce="{{ csp_nonce() }}">
(function () {
    var MAX_GAP_MS = 50, MIN_LENGTH = 4;
    var buffer = '', lastTime = 0;
    var handlerName = @json($onScan ?? null);

    document.addEventListener('keydown', function (e) {
        var t = e.target;
        var tag = t && t.tagName;
        var isFormField = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (t && t.isContentEditable);
        var optIn = t && t.dataset && t.dataset.barcodeTarget !== undefined;
        if (isFormField && !optIn) return;

        var now = performance.now();
        var gap = now - lastTime;
        lastTime = now;

        if (e.key === 'Enter') {
            if (buffer.length >= MIN_LENGTH) {
                var code = buffer;
                buffer = '';
                if (handlerName && typeof window[handlerName] === 'function') window[handlerName](code);
            } else {
                buffer = '';
            }
            return;
        }
        if (gap > MAX_GAP_MS) buffer = '';
        if (e.key.length === 1) buffer += e.key;
    });
})();
</script>
