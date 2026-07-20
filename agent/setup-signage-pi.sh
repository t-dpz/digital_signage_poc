#!/usr/bin/env bash
# setup-signage-pi.sh — provisions a Linux/systemd signage device end to end:
# the cage+chromium kiosk browser on tty1, the remote-command agent, and the
# narrow sudoers grant it needs. Built from the known-working setup on
# 404-signage01 (screen "Scherm KLUIS").
#
# Safe to re-run any time the server URL or screen token changes — just run
# it again on the device. Re-running with the same answers is a no-op (no
# service bounce) except for re-fetching the latest signage-agent.sh.
#
# Usage:
#   sudo bash setup-signage-pi.sh
#   sudo bash setup-signage-pi.sh http://devdeb.404.gent fd5d60bed6ed5010216a791c91d70ad7
# (positional args are optional — prompted for interactively if omitted,
# pre-filled with whatever's already configured on this device)
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
    echo "Run this as root: sudo bash $0" >&2
    exit 1
fi

AGENT_CONF=/etc/signage-agent.conf
KIOSK_UNIT_FILE=/etc/systemd/system/kiosk.service
AGENT_UNIT_FILE=/etc/systemd/system/signage-agent.service
BOOTINFO_UNIT_FILE=/etc/systemd/system/bootinfo.service
SUDOERS_FILE=/etc/sudoers.d/signage-agent
AGENT_SCRIPT=/opt/signage-agent/signage-agent.sh

# ---------------------------------------------------------------- inputs --

CUR_SERVER=""
CUR_TOKEN=""
if [ -f "$AGENT_CONF" ]; then
    # shellcheck disable=SC1090
    . "$AGENT_CONF"
    CUR_SERVER="${SERVER_URL:-}"
    CUR_TOKEN="${SCREEN_TOKEN:-}"
fi

SIGNAGE_SERVER="${1:-${SIGNAGE_SERVER:-}}"
if [ -z "$SIGNAGE_SERVER" ]; then
    read -rp "Signage server URL [${CUR_SERVER:-none}]: " IN
    SIGNAGE_SERVER="${IN:-$CUR_SERVER}"
fi
SIGNAGE_SERVER="${SIGNAGE_SERVER%/}"

SIGNAGE_TOKEN="${2:-${SIGNAGE_TOKEN:-}}"
if [ -z "$SIGNAGE_TOKEN" ]; then
    read -rp "Screen token (from its player.php?token=... URL) [${CUR_TOKEN:-none}]: " IN
    SIGNAGE_TOKEN="${IN:-$CUR_TOKEN}"
fi

if [ -z "$SIGNAGE_SERVER" ] || [ -z "$SIGNAGE_TOKEN" ]; then
    echo "Both a server URL and a token are required." >&2
    exit 1
fi
if ! [[ "$SIGNAGE_SERVER" =~ ^https?:// ]]; then
    echo "Server URL must start with http:// or https:// (got: $SIGNAGE_SERVER)" >&2
    exit 1
fi
if ! [[ "$SIGNAGE_TOKEN" =~ ^[a-f0-9]{16,64}$ ]]; then
    echo "Token doesn't look like a valid signage token (16-64 lowercase hex chars)." >&2
    exit 1
fi

CUR_KIOSK_USER=""
if [ -f "$KIOSK_UNIT_FILE" ]; then
    CUR_KIOSK_USER="$(sed -n 's/^User=//p' "$KIOSK_UNIT_FILE" | head -1)"
fi
KIOSK_USER="${KIOSK_USER:-${CUR_KIOSK_USER:-${SUDO_USER:-}}}"
if [ -z "$KIOSK_USER" ] || [ "$KIOSK_USER" = "root" ]; then
    read -rp "Local user account to run the kiosk browser as: " KIOSK_USER
fi
if ! id "$KIOSK_USER" >/dev/null 2>&1; then
    echo "User '$KIOSK_USER' doesn't exist on this device — create it first." >&2
    exit 1
fi

echo "==> server=$SIGNAGE_SERVER  token=$SIGNAGE_TOKEN  kiosk_user=$KIOSK_USER"

# ------------------------------------------------------------- packages --

echo "==> Installing packages (cage, curl, chromium, emoji font)..."
apt-get update -qq
# fonts-noto-color-emoji: Chromium never ships its own emoji glyphs, and a stock
# Debian install has no color emoji font — without this, emoji in takeover-page
# text (or any playlist content) render as blank/tofu boxes on the kiosk.
apt-get install -y --no-install-recommends cage curl fonts-noto-color-emoji >/dev/null
if ! command -v chromium >/dev/null 2>&1 && ! command -v chromium-browser >/dev/null 2>&1; then
    apt-get install -y --no-install-recommends chromium >/dev/null 2>&1 \
        || apt-get install -y --no-install-recommends chromium-browser >/dev/null
fi
CHROMIUM_BIN="$(command -v chromium || command -v chromium-browser)"

HAVE_FASTFETCH=0
if command -v fastfetch >/dev/null 2>&1 || apt-get install -y fastfetch >/dev/null 2>&1; then
    HAVE_FASTFETCH=1
fi

# ------------------------------------------------- install_if_changed() --
# Writes $2 (content, from stdin) to $1 only if different from what's there;
# echoes "changed" or "unchanged" so callers know whether to restart a unit.
install_if_changed() {
    local target="$1" tmp
    tmp="$(mktemp)"
    cat > "$tmp"
    if [ -f "$target" ] && cmp -s "$tmp" "$target"; then
        rm -f "$tmp"
        echo "unchanged"
    else
        mkdir -p "$(dirname "$target")"
        mv "$tmp" "$target"
        echo "changed"
    fi
}

# --------------------------------------------------------- agent script --

echo "==> Fetching signage-agent.sh from $SIGNAGE_SERVER..."
mkdir -p /opt/signage-agent
curl -fsS "$SIGNAGE_SERVER/agent/signage-agent.sh" -o /opt/signage-agent/signage-agent.sh
chmod +x /opt/signage-agent/signage-agent.sh

# ------------------------------------------------------------- agent conf-

agent_conf_state="$(install_if_changed "$AGENT_CONF" <<EOF
SERVER_URL=$SIGNAGE_SERVER
SCREEN_TOKEN=$SIGNAGE_TOKEN
POLL_INTERVAL=30
KIOSK_UNIT=kiosk.service
EOF
)"

# ------------------------------------------------------ dedicated user ---

if ! id -u signage-agent >/dev/null 2>&1; then
    useradd --system --no-create-home --shell /usr/sbin/nologin signage-agent
fi

# ------------------------------------------------------------- sudoers ---

install_if_changed "$SUDOERS_FILE" <<'EOF' >/dev/null
signage-agent ALL=(root) NOPASSWD: /usr/bin/systemctl restart kiosk.service, /usr/bin/systemctl reboot
EOF
chmod 0440 "$SUDOERS_FILE"
visudo -cf "$SUDOERS_FILE"

# --------------------------------------------------- signage-agent unit -

install_if_changed "$AGENT_UNIT_FILE" <<'EOF' >/dev/null
[Unit]
Description=Signage remote-command agent
After=network-online.target
Wants=network-online.target

[Service]
User=signage-agent
ExecStart=/opt/signage-agent/signage-agent.sh
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

# -------------------------------------------------------- bootinfo unit -

if [ "$HAVE_FASTFETCH" -eq 1 ]; then
    install_if_changed "$BOOTINFO_UNIT_FILE" <<'EOF' >/dev/null
[Unit]
Description=Show fastfetch on console before the kiosk starts
After=network-online.target
Before=kiosk.service

[Service]
Type=oneshot
RemainAfterExit=yes
Environment=TERM=linux
StandardOutput=tty
TTYPath=/dev/tty1
ExecStartPre=/usr/bin/clear
ExecStart=/usr/bin/fastfetch
ExecStart=/bin/sleep 10
EOF
fi

# --------------------------------------------------------- kiosk unit ---

BOOTINFO_LINES=""
if [ "$HAVE_FASTFETCH" -eq 1 ]; then
    BOOTINFO_LINES=$'After=bootinfo.service\nWants=bootinfo.service'
fi

kiosk_state="$(install_if_changed "$KIOSK_UNIT_FILE" <<EOF
[Unit]
Description=Signage kiosk (cage + chromium)
After=network-online.target
Conflicts=getty@tty1.service
After=getty@tty1.service
$BOOTINFO_LINES

[Service]
User=$KIOSK_USER
PAMName=login
TTYPath=/dev/tty1
StandardInput=tty
StandardOutput=journal
StandardError=journal
UtmpIdentifier=tty1
UtmpMode=user
Environment=PLAYER_URL=$SIGNAGE_SERVER/player.php?token=$SIGNAGE_TOKEN
Environment=XCURSOR_THEME=transparent
Environment=XCURSOR_SIZE=24
ExecStart=/usr/bin/cage -d -- $CHROMIUM_BIN \\
    --kiosk \\
    --ozone-platform=wayland \\
    --noerrdialogs \\
    --disable-session-crashed-bubble \\
    --disable-infobars \\
    --disable-crash-reporter \\
    --autoplay-policy=no-user-gesture-required \\
    --check-for-update-interval=31536000 \\
    --unsafely-treat-insecure-origin-as-secure=$SIGNAGE_SERVER \\
    \${PLAYER_URL}
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF
)"

# --------------------------------------------------------------- apply --

systemctl daemon-reload

systemctl enable --now signage-agent.service >/dev/null
systemctl enable --now kiosk.service >/dev/null
# bootinfo.service has no [Install] section by design — kiosk.service pulls it
# in via Wants=/After=, so it's never "enabled" on its own (systemctl enable
# would just warn about that and do nothing).

# Always restart the agent — cheap, invisible, and picks up a freshly-fetched
# signage-agent.sh even when the config itself didn't change.
systemctl restart signage-agent.service
echo "==> signage-agent.service restarted (config: $agent_conf_state)"

if [ "$kiosk_state" = "changed" ]; then
    echo "==> kiosk.service config changed — restarting (screen will blank briefly)"
    systemctl restart kiosk.service
else
    echo "==> kiosk.service unchanged — left running"
fi

echo
echo "Done. Kiosk pointed at $SIGNAGE_SERVER/player.php?token=$SIGNAGE_TOKEN"
echo "Re-run this script any time the server or token changes."
