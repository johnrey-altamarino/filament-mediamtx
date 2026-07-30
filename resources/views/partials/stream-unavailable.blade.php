{{-- Shown to viewers who are not eligible to watch (blocked IP, disallowed embed
     origin, direct playback off). The copy is deliberately identical for every
     cause so it never reveals which gate rejected the request. --}}
<div id="stream-unavailable" class="stream-unavailable{{ ($hidden ?? false) ? ' hidden' : '' }}" role="alert">
    <svg class="su-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <circle cx="12" cy="12" r="9.25" fill="none" stroke="currentColor" stroke-width="1.4" />
        <path d="M9.6 8.5v7l5.4-3.5z" fill="currentColor" />
        <line x1="5.6" y1="18.4" x2="18.4" y2="5.6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" />
    </svg>
    <h1 class="su-title">Stream unavailable</h1>
    <p class="su-text">This video isn't available here.</p>
</div>
<style>
    .stream-unavailable {
        position: fixed;
        inset: 0;
        z-index: 10;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 14px;
        padding: 24px;
        box-sizing: border-box;
        background: radial-gradient(ellipse at center, #141414 0%, #000 72%);
        color: #fff;
        font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
        text-align: center;
    }
    .stream-unavailable.hidden { display: none; }
    .stream-unavailable .su-icon {
        width: clamp(44px, 14vmin, 76px);
        height: auto;
        color: rgba(255, 255, 255, 0.42);
        animation: su-breathe 3s ease-in-out infinite;
    }
    .stream-unavailable .su-title {
        margin: 0;
        font-size: clamp(0.95rem, 3.4vmin, 1.25rem);
        font-weight: 600;
        letter-spacing: 0.01em;
        color: rgba(255, 255, 255, 0.92);
    }
    .stream-unavailable .su-text {
        margin: 0;
        max-width: 26rem;
        font-size: clamp(0.78rem, 2.6vmin, 0.95rem);
        line-height: 1.5;
        color: rgba(255, 255, 255, 0.55);
    }
    @keyframes su-breathe {
        0%, 100% { opacity: 0.55; }
        50%      { opacity: 1; }
    }
    @media (prefers-reduced-motion: reduce) {
        .stream-unavailable .su-icon { animation: none; opacity: 0.75; }
    }
</style>
