<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Multistream Monitor</title>
    @vite('resources/css/app.css')
    @livewireStyles
    <style>
        html, body { margin: 0; padding: 0; min-height: 100%; background: #0a0a0a; }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body>
    {{ $slot }}
    @livewireScripts
    <script>
        // Copy helper that also works over plain HTTP. navigator.clipboard is only
        // available in a secure context (HTTPS or localhost); on http:// it is
        // undefined and writeText() throws. Fall back to a hidden <textarea> +
        // execCommand('copy'). Returns a Promise<boolean> indicating success.
        window.monitorCopy = async function (text) {
            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(text);
                    return true;
                }
            } catch (e) { /* fall through to legacy path */ }
            try {
                const ta = document.createElement('textarea');
                ta.value = text;
                ta.setAttribute('readonly', '');
                ta.style.position = 'fixed';
                ta.style.top = '-9999px';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.focus();
                ta.select();
                const ok = document.execCommand('copy');
                document.body.removeChild(ta);
                return ok;
            } catch (e) { return false; }
        };

        // Make the 12s wire:poll resilient. When a poll's POST /livewire/update
        // hits a transient origin/edge failure (Cloudflare 520/522, a 5xx, or a
        // dropped connection), Livewire's default is to render the error response
        // as a full-screen overlay — which is how a momentary blip blanks the whole
        // monitor with Cloudflare's 520 page. Swallow those: keep the current tiles
        // on screen (iframes are wire:ignore'd, so streams keep playing) and let the
        // next poll recover on its own. Real Livewire errors (4xx) still surface.
        document.addEventListener('livewire:init', () => {
            Livewire.hook('request', ({ fail }) => {
                fail(({ status, preventDefault }) => {
                    if (status === 0 || status >= 500) {
                        console.warn(`Monitor poll failed (${status}); keeping current view, will retry.`);
                        preventDefault();
                    }
                });
            });
        });
    </script>
</body>
</html>
