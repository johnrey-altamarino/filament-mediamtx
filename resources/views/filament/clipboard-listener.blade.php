<script>
    window.addEventListener('clipboard-copy', async (event) => {
        try {
            const text = event?.detail?.text ?? '';
            if (!text) return;

            await navigator.clipboard.writeText(text);

            // Optional: you can also console.log('Copied');
        } catch (e) {
            console.error('Clipboard copy failed', e);
        }
    });
</script>
