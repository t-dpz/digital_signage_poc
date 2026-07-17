# signage-agent — remote kiosk restart / OS reboot companion

A browser tab (the `player.php` kiosk player) has no web API to restart its own
host process or reboot the machine it's running on. This directory holds a small
companion process that runs *outside* the browser, on the kiosk device itself,
polls the admin server for a pending command, and carries it out with OS
permission. It's entirely optional — screens work fine without it; you just
won't be able to use the "Restart kiosk" / "Reboot device" buttons on that
screen's admin page until the agent is installed there.

**Linux/systemd devices only** (Raspberry Pi, generic Linux mini-PCs). **Not
supported on Samsung Tizen SoC displays** — Tizen has no standard
shell/systemd/cron environment accessible to third-party code. Remote
restart/reboot there would require Samsung's MDC (Multi Display Control)
protocol or a Tizen web-app with elevated privileges — a fundamentally
different integration, not a polling shell agent. Tracked as a known gap /
future work, not solved by this feature.

## How it works

The admin panel's "Kiosk control" card on a screen's detail page queues a
single pending command (`restart_kiosk` or `reboot`) for that screen. The
agent polls `GET api.php?action=command&token=<screen token>` on its own
interval (independent of the player's own `player_refresh` manifest/heartbeat
polling — this is a separate control channel). The server clears the pending
command **the instant it's fetched**, before the agent has actually executed
it. This is deliberate: it makes delivery at-most-once. If a device loses
power between fetching a `reboot` command and actually rebooting, the server
has already forgotten about it — the device just boots up normally, once,
next time. The alternative (clearing only after an acknowledged reboot) risks
a boot loop: the device would see the same `reboot` command again on its next
boot and reboot immediately, forever. A rarely-lost command is the safe
failure mode; a loop is not.

## Install

**Fast path:** `curl -O http://<server>/agent/setup-signage-pi.sh && sudo bash setup-signage-pi.sh` —
prompts for the server URL and screen token, installs cage + chromium, the
agent, its sudoers grant, and a `kiosk.service` unit, all in one go. Safe to
re-run any time the server or token changes (re-run with no args and it
pre-fills the current values — just press enter to keep one and retype the
other). See its own comments for what it does; the steps below are what it
automates, kept here for anyone provisioning by hand or auditing what it does.

1. Copy `signage-agent.sh` to `/opt/signage-agent/signage-agent.sh` on the
   device and `chmod +x` it.
2. Create a dedicated low-privileged user: `useradd --system --no-create-home signage-agent`.
3. Install `signage-agent.sudoers.example` as `/etc/sudoers.d/signage-agent`
   (`chmod 0440`, then `visudo -c` to validate). This grants the agent user
   exactly two commands as root — nothing more. See the privilege-model note
   below for why this isn't just "run the agent as root."
4. Create `/etc/signage-agent.conf`:
   ```
   SERVER_URL=https://your-signage-server
   SCREEN_TOKEN=<this screen's token, from its player URL>
   POLL_INTERVAL=30
   KIOSK_UNIT=signage-kiosk.service
   ```
5. If you don't already have a systemd unit launching the kiosk browser, copy
   `signage-kiosk.service.example` to `/etc/systemd/system/signage-kiosk.service`
   and adapt the browser binary path/flags for your device — this repo has no
   existing kiosk-launch mechanism, so it's a template, not a drop-in.
6. Install `signage-agent.service` as `/etc/systemd/system/signage-agent.service`.
7. `systemctl daemon-reload && systemctl enable --now signage-kiosk.service signage-agent.service`.

## Privilege model

The agent runs as a dedicated non-root user, not root, with a narrowly-scoped
`sudoers.d` entry for exactly two fully-qualified commands (`systemctl restart
signage-kiosk.service`, `systemctl reboot`) — no wildcards. The agent is the
highest-exposure new component in this system: it polls a network endpoint and
parses server-controlled input in a loop. Running it as root would mean any
bug in that polling/parsing logic (or a future extension to the command
vocabulary) executes with full root privileges. With the narrow sudoers grant,
a compromised agent process can restart the one named unit or reboot — which
is already its intended capability, so nothing is lost — but it can't read
arbitrary root-owned files, install persistence, or do anything else as root.

## Security note

Screen tokens are already capability URLs (see the main CLAUDE.md, invariant
6) — anyone holding one already controls what plays on that screen. This
feature extends the same token to *"can reboot the physical device,"* a real
escalation in blast radius (physical availability, not just content) that
you should consciously accept, not something introduced silently. No new
auth mechanism is added — the `command` action reuses `screen_by_token()`
exactly like `manifest`/`heartbeat`. Mitigations are operational, not new
code-level access control:
- **Serve the admin/player/api over HTTPS in production.** The main app
  already recommends this for Cache API/Wake Lock on players; now the same
  token also carries reboot capability over this channel.
- **Token regeneration is the revocation mechanism**, same as today — if a
  token leaks, regenerate it from the screen's admin page.

## Testing

Manually verify at-most-once delivery before trusting this on real hardware:

```bash
curl "https://your-server/api.php?action=command&token=<token>"
# {"command":null}

# click "Restart kiosk" on that screen's admin page, then:
curl "https://your-server/api.php?action=command&token=<token>"
# {"command":"restart_kiosk"}

curl "https://your-server/api.php?action=command&token=<token>"
# {"command":null}   <- proves the command was cleared on first fetch, not reissued
```
