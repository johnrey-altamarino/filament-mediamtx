<?php

namespace App\Support;

use Jenssegers\Agent\Agent;

/**
 * Turns a raw User-Agent string into a short, human label for the Live
 * Sessions table (e.g. "OBS Studio", "Edge / Windows").
 *
 * MediaMTX now reports a userAgent per WebRTC session. Publishers are usually
 * encoders (OBS, FFmpeg, GStreamer, ...) that jenssegers/agent does not
 * recognise as browsers, so those are matched explicitly first; anything else
 * falls back to the same Agent parsing used by ViewerRequestLogger.
 */
class UserAgentLabel
{
    /**
     * Known encoder / non-browser publishing clients, matched (case-insensitively)
     * as a needle anywhere in the UA string => display label.
     */
    private const ENCODERS = [
        'OBS-Studio' => 'OBS Studio',
        'FFmpeg' => 'FFmpeg',
        'Lavf' => 'FFmpeg',            // libavformat, used by ffmpeg
        'GStreamer' => 'GStreamer',
        'Larix' => 'Larix',
        'vMix' => 'vMix',
        'libwebrtc' => 'libwebrtc',
        'gortc' => 'gortc',
    ];

    public static function format(?string $ua): string
    {
        $ua = trim((string) $ua);

        if ($ua === '') {
            return 'Unknown';
        }

        foreach (self::ENCODERS as $needle => $label) {
            if (stripos($ua, $needle) !== false) {
                return $label;
            }
        }

        $agent = new Agent;
        $agent->setUserAgent($ua);

        $browser = $agent->browser() ?: '';
        $platform = $agent->platform() ?: '';

        if ($browser !== '' && $platform !== '') {
            return "{$browser} / {$platform}";
        }

        if ($browser !== '') {
            return $browser;
        }

        if ($platform !== '') {
            return $platform;
        }

        // Unrecognised: show the first token so it is still identifiable.
        return strtok($ua, ' /;') ?: 'Unknown';
    }
}
