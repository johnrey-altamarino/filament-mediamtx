# Fixing WebRTC playback for viewers on the same ISP/LAN as the server

## Symptom

WebRTC playback (and publishing) worked from **other** networks but failed for
clients on the **same LAN / same ISP** as the on-prem server. The browser showed:

```
OPTIONS https://play.jrta.online/<stream>/whep  net::ERR_CONNECTION_TIMED_OUT
WebRTC error: TypeError: Failed to fetch, retrying in some seconds
```

The connection timed out before reaching the server. A direct test confirmed it:

| From a LAN client | Result |
|---|---|
| `curl https://play.jrta.online/` (resolves to **public** IP `180.191.138.140`) | **timeout** |
| `curl -k https://192.168.254.103/` (server **LAN** IP) | connects, MediaMTX responds |

## Root cause: NAT hairpinning (NAT loopback)

The server is behind a **Globe 5G CPE (ZLT G3000A)** via ISP NAT with port
forwarding. `play.jrta.online` resolved to the public IP `180.191.138.140`.

- **External client** → hits the public IP from outside → port-forward works. ✅
- **Same-LAN client** → DNS gives the public IP → the packet goes up to the router
  and must "hairpin" back down to the LAN server. The ZLT (like most consumer/5G
  CPEs) does **not** support NAT hairpinning, so the packet is dropped → timeout. ❌

This is purely a network-reachability problem. It is **not** Apache, CORS, or JWT —
the request never reaches the server, so nothing server-side can fix it.

### Why Apache / the app can't fix it
DNS resolution and the TCP connection happen **on the client, before** the request
reaches the server. Apache runs *on* the server, downstream of the failure, so it
can never see the dropped connection. The only levers are at the DNS / network layer.

## The fix: front the signaling with Cloudflare's proxy

The Laravel admin app already worked on the same LAN because its DNS record is
**Proxied (orange cloud)** in Cloudflare — clients connect to a Cloudflare edge IP
(out on the internet), not the home public IP, so there is no hairpin. The streaming
record `play` was **DNS-only (grey cloud)**, pointing straight at the home IP, which
is why it hairpinned.

The fix is to give the streaming hostname the same treatment, while keeping the
**WebRTC media** path pointed at the real origin (Cloudflare's proxy carries only
HTTP/S signaling, not the UDP media).

### Two-phase model (key mental model)

1. **Signaling** — the WHEP/WHIP HTTP(S) request. This is what was timing out.
   Routing it through Cloudflare removes the hairpin.
2. **Media** — the actual audio/video over UDP. This does **not** traverse Cloudflare.
   It goes directly to whatever IP MediaMTX advertises as an ICE candidate, so those
   candidates must stay pointed at reachable origin IPs.

### Steps

**1. MediaMTX — point media candidates at real IPs, not the proxied hostname.**

In `mediamtx.yml`:

```yaml
# BEFORE — the hostname would resolve to Cloudflare once proxied, breaking media
webrtcAdditionalHosts: ["play.jrta.online"]

# AFTER — literal IPs so media always reaches the real origin
webrtcAdditionalHosts: ["180.191.138.140", "192.168.254.103"]
```

- `180.191.138.140` (public) → external viewers/publishers reach media via the
  UDP `8189` / TCP `8089` port-forward.
- `192.168.254.103` (LAN) → same-LAN viewers reach media directly over the LAN.

> A grey-cloud hostname such as `origin.jrta.online → 180.191.138.140` can be used
> instead of the literal public IP if the IP is dynamic — but **spell it correctly**
> (a typo like `origin.rta.online` produces `lookup ... server misbehaving` in the
> MediaMTX log and breaks the session). Using literal IPs removes the DNS variable.

Restart and confirm it came back up:

```bash
sudo systemctl restart mediamtx
sudo systemctl status mediamtx --no-pager
```

**2. Cloudflare — proxy the signaling record.**
DNS → the `play` record → set **Proxied (orange cloud)**.

**3. Cloudflare — SSL/TLS mode = Full (strict).**
The origin is Apache on `443` with a valid Let's Encrypt cert. "Flexible" would make
Cloudflare hit port `80` and break; "Full (strict)" makes it connect to `443`.

**4. Keep the port-forwards** on the ZLT → `192.168.254.103`: UDP `8189`, TCP `8089`
(WebRTC media) and TCP `443` (signaling origin). Cloudflare only fronts signaling;
external media still needs the UDP/TCP forwards.

**5. Apache needs no changes.** The existing vhost terminates TLS and proxies to
`http://127.0.0.1:8889`. `ProxyPreserveHost On` keeps the `Host` header, and CORS
stays owned by MediaMTX (`webrtcAllowOrigins`). Do **not** add CORS headers in
Apache or enable Cloudflare CORS features, or the browser sees duplicate
`Access-Control-Allow-Origin` values.

## Publishing (WHIP)

Publish and read share the same MediaMTX server (`:8889`) and the same path through
Cloudflare. Use the same host:

```
Publish (WHIP):  https://play.jrta.online/<stream>/whip
Read   (WHEP):   https://play.jrta.online/<stream>/whep
```

- A **remote** publisher (e.g. OBS on another network) reaches media via the public
  IP candidate — it does **not** need the proxy and does not hairpin.
- A **same-LAN** publisher benefits from the proxy exactly like a viewer (its
  signaling would otherwise hairpin).
- The publish token (`Authorization: Bearer …`) is forwarded untouched by Cloudflare
  and Apache. Non-browser publishers (OBS/ffmpeg) send no `Origin`, so
  `webrtcAllowOrigins` does not gate them.

## Testing

### On the LAN
```cmd
ipconfig /flushdns
nslookup play.jrta.online 1.1.1.1      :: should return Cloudflare IPs (104.x / 172.67.x)
curl -v --connect-timeout 5 https://play.jrta.online/   :: should connect, not time out
```
> Note: on the ZLT LAN, plain `nslookup` may time out against the router's IPv6
> resolver (`fe80::1`). That's a quirk of the `nslookup` tool — the browser's system
> resolver still works. Use `nslookup ... 1.1.1.1` or `curl` to verify.

Then load the player. In `chrome://webrtc-internals`, the selected remote candidate
should be `192.168.254.103` (media took the LAN path).

### Outside the network
From a different ISP (e.g. phone on mobile data), load the player — video plays.
The selected remote candidate should be `180.191.138.140` (media via port-forward).

### Success criteria
| Test | Expected |
|---|---|
| LAN: `nslookup play.jrta.online 1.1.1.1` | Cloudflare IP, not `180.191.138.140` |
| LAN: browser player | plays; webrtc-internals remote candidate `192.168.254.103` |
| External: browser player | plays; remote candidate `180.191.138.140` |
| `curl -X OPTIONS .../whep -H 'Origin: https://studio.jrta.online'` | 204, exactly one `Access-Control-Allow-Origin` |

## Troubleshooting

**`404 / stream not found`** — signaling reached MediaMTX, but nothing is publishing
that path. Start the encoder; verify with:
```bash
curl -s http://127.0.0.1:9997/v3/paths/list | python3 -m json.tool | grep -A6 <stream>
```
(Restarting MediaMTX drops the active publisher — it must reconnect.)

**`lookup <host> ... server misbehaving`** — a hostname in `webrtcAdditionalHosts`
doesn't resolve (often a typo). Fix the spelling or use a literal IP.

**Slow to load + log flooded with `RTP packets lost` / `invalid FU-A packet
(non-starting)`** — this is **packet loss on the publish (ingest) stream**, not a
proxy/DNS problem. A WebRTC player can't render until it receives one *complete*
keyframe; loss corrupts keyframes, so the player waits for the next intact one.
Mitigate on the publisher:
- Lower the video **bitrate** (biggest lever) — try 1500–2500 kbps.
- Set OBS **keyframe interval = 1s** (faster initial load and recovery).
- Lower resolution/FPS if needed.
- Check both uplinks (publisher upload + the server's 5G reception).

Watch the effect:
```bash
sudo journalctl -u mediamtx -f | grep -E "packets lost|processing errors"
```

## Rollback

- Flip `play` back to **grey (DNS only)** in Cloudflare.
- Restore `mediamtx.yml` from backup (`webrtcAdditionalHosts: ["play.jrta.online"]`)
  and `sudo systemctl restart mediamtx`.

This returns to the original state: external playback works, same-LAN does not.

## Reference values (this deployment)

| Item | Value |
|---|---|
| Server LAN IP | `192.168.254.103` |
| Public IP | `180.191.138.140` |
| Signaling host (proxied) | `play.jrta.online` → Apache `:443` → MediaMTX `:8889` |
| Player page origin | `https://studio.jrta.online` |
| WebRTC media ports | UDP `8189`, TCP `8089` (forwarded on the ZLT) |
| Router | Globe ZLT G3000A 5G CPE (`http://192.168.254.254`) — no NAT hairpin, no local DNS host-mapping |
