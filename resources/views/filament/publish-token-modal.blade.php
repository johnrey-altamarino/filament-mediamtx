<div class="space-y-4">

    {{-- PATH --}}
    <div>
        <div class="text-sm text-gray-500 mb-1">Stream Path</div>

        <div
            x-data="{ copied: false }"
            x-init="pathValue = @js($path)"
            class="flex items-center gap-2"
        >
            <div class="font-medium break-all">{{ $path }}</div>

            <button
                @click="
                    navigator.clipboard.writeText(pathValue);
                    copied = true;
                    setTimeout(() => copied = false, 2000);
                "
                class="px-2 py-1 text-xs bg-gray-800 text-white rounded"
            >
                <span x-show="!copied">Copy</span>
                <span x-show="copied">Copied ✓</span>
            </button>
        </div>
    </div>

    {{-- TOKEN --}}
    <div>
        <div class="text-sm text-gray-500 mb-2">
            Bearer Token (paste into OBS → Settings → Stream → WHIP → Bearer Token)
        </div>

        <div
            x-data="{ copied: false }"
            x-init="tokenValue = 'Bearer ' + @js($token)"
            class="relative"
        >
            <pre class="p-3 rounded-lg bg-gray-900 text-gray-100 overflow-auto text-xs whitespace-pre-wrap break-all">
Bearer {{ $token }}
            </pre>

            <button
                @click="
                    navigator.clipboard.writeText(tokenValue);
                    copied = true;
                    setTimeout(() => copied = false, 2000);
                "
                class="absolute top-2 right-2 px-2 py-1 text-xs bg-gray-700 text-white rounded"
            >
                <span x-show="!copied">Copy</span>
                <span x-show="copied">Copied ✓</span>
            </button>
        </div>
    </div>

</div>
