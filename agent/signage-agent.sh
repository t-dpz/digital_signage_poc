#!/usr/bin/env bash
# signage-agent.sh — polls the signage server for a pending remote command
# (restart_kiosk | reboot) and executes it locally. Runs as a dedicated
# low-privileged user; the two commands it can run are granted via a narrow
# sudoers.d entry (see signage-agent.sudoers.example). See README.md.
#
# Linux/systemd devices only — not usable on Samsung Tizen SoC displays
# (no shell/systemd environment there).
set -u

CONF="${SIGNAGE_AGENT_CONF:-/etc/signage-agent.conf}"
if [ -f "$CONF" ]; then
    # shellcheck source=/dev/null
    . "$CONF"
fi

SERVER_URL="${SERVER_URL:?SERVER_URL not set (in $CONF)}"
SCREEN_TOKEN="${SCREEN_TOKEN:?SCREEN_TOKEN not set (in $CONF)}"
POLL_INTERVAL="${POLL_INTERVAL:-30}"
KIOSK_UNIT="${KIOSK_UNIT:-signage-kiosk.service}"

log() { echo "[signage-agent] $(date '+%Y-%m-%d %H:%M:%S') $*"; }

# Extract the "command" field from a tiny {"command":null|"restart_kiosk"|"reboot"}
# JSON body without requiring jq (feature-detect it, fall back to grep/sed so this
# runs on minimal images too).
parse_command() {
    if command -v jq >/dev/null 2>&1; then
        printf '%s' "$1" | jq -r '.command // empty'
    else
        printf '%s' "$1" | grep -o '"command"[[:space:]]*:[[:space:]]*"[a-z_]*"' | sed -E 's/.*"([a-z_]+)"$/\1/'
    fi
}

log "starting — server=$SERVER_URL interval=${POLL_INTERVAL}s kiosk_unit=$KIOSK_UNIT"

while true; do
    resp="$(curl -fsS --max-time 10 "$SERVER_URL/api.php?action=command&token=$SCREEN_TOKEN" 2>/dev/null)"
    if [ $? -ne 0 ] || [ -z "$resp" ]; then
        log "poll failed (network/server) — will retry"
        sleep "$POLL_INTERVAL"
        continue
    fi

    cmd="$(parse_command "$resp")"
    case "$cmd" in
        restart_kiosk)
            log "received restart_kiosk — restarting $KIOSK_UNIT"
            sudo /usr/bin/systemctl restart "$KIOSK_UNIT"
            ;;
        reboot)
            log "received reboot — rebooting now"
            sudo /usr/bin/systemctl reboot
            ;;
        ""|null)
            : # nothing pending
            ;;
        *)
            log "unknown command '$cmd' — ignoring"
            ;;
    esac

    sleep "$POLL_INTERVAL"
done
