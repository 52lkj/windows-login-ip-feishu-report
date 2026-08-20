#!/usr/bin/env bash
set -euo pipefail

WebhookUrl="${FEISHU_WEBHOOK_URL:-}"
InstallDir="${WLIFF_INSTALL_DIR:-/opt/windows-login-ip-feishu-report}"
EnvFile="${WLIFF_ENV_FILE:-/etc/windows-login-ip-feishu-report.env}"
ServiceName="${WLIFF_SERVICE_NAME:-windows-login-ip-feishu-report}"
TaskTime="${WLIFF_TASK_TIME:-18:00}"
Mode="${1:---today}"

usage() {
    cat <<'EOF'
Usage:
  FEISHU_WEBHOOK_URL='https://open.feishu.cn/open-apis/bot/v2/hook/xxxx' ./linux-login-ip-feishu-report.sh --today
  sudo FEISHU_WEBHOOK_URL='https://open.feishu.cn/open-apis/bot/v2/hook/xxxx' ./linux-login-ip-feishu-report.sh --install

Options:
  --today       Collect today's successful Linux login records and send a Feishu message.
  --install     Install the script and a systemd timer.
  --help        Show this help.

Environment:
  FEISHU_WEBHOOK_URL   Feishu bot webhook URL.
  WLIFF_INSTALL_DIR    Install directory. Default: /opt/windows-login-ip-feishu-report
  WLIFF_TASK_TIME      Daily timer time in HH:mm. Default: 18:00
EOF
}

require_webhook() {
    if [[ -z "${WebhookUrl// }" ]]; then
        if [[ -r /dev/tty ]]; then
            read -r -p "Feishu webhook URL: " WebhookUrl </dev/tty
        else
            echo "FEISHU_WEBHOOK_URL is required." >&2
            exit 1
        fi
    fi

    if [[ -z "${WebhookUrl// }" ]]; then
        echo "Feishu webhook URL is required." >&2
        exit 1
    fi
}

json_escape() {
    if command -v python3 >/dev/null 2>&1; then
        python3 -c 'import json,sys; print(json.dumps(sys.stdin.read())[1:-1])'
    else
        sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' -e ':a;N;$!ba;s/\n/\\n/g'
    fi
}

send_feishu_text() {
    local text="$1"
    local escaped
    escaped="$(printf '%s' "$text" | json_escape)"
    local payload="{\"msg_type\":\"text\",\"content\":{\"text\":\"$escaped\"}}"

    if command -v curl >/dev/null 2>&1; then
        curl -fsS -H "Content-Type: application/json; charset=utf-8" -d "$payload" "$WebhookUrl" >/dev/null
    elif command -v wget >/dev/null 2>&1; then
        wget -qO- --header="Content-Type: application/json; charset=utf-8" --post-data="$payload" "$WebhookUrl" >/dev/null
    else
        echo "curl or wget is required." >&2
        exit 1
    fi
}

get_public_ip() {
    local ip=""

    if command -v curl >/dev/null 2>&1; then
        ip="$(curl -fsS --max-time 8 https://api.ipify.org 2>/dev/null || true)"
        [[ -n "$ip" ]] || ip="$(curl -fsS --max-time 8 https://ifconfig.me/ip 2>/dev/null || true)"
        [[ -n "$ip" ]] || ip="$(curl -fsS --max-time 8 https://checkip.amazonaws.com 2>/dev/null || true)"
    elif command -v wget >/dev/null 2>&1; then
        ip="$(wget -qO- --timeout=8 https://api.ipify.org 2>/dev/null || true)"
        [[ -n "$ip" ]] || ip="$(wget -qO- --timeout=8 https://ifconfig.me/ip 2>/dev/null || true)"
        [[ -n "$ip" ]] || ip="$(wget -qO- --timeout=8 https://checkip.amazonaws.com 2>/dev/null || true)"
    fi

    printf '%s\n' "$ip" | tr -d '[:space:]'
}

collect_from_last() {
    if ! command -v last >/dev/null 2>&1; then
        return 0
    fi

    LC_ALL=C last -iF -w -s today 2>/dev/null | awk '
        /^wtmp begins/ { next }
        /^reboot/ { next }
        /^shutdown/ { next }
        /^$/ { next }
        {
            user=$1
            tty=$2
            host=$3

            if (host == "" || host == ":0" || host == "0.0.0.0" || host == "-" || host ~ /^(Mon|Tue|Wed|Thu|Fri|Sat|Sun)$/) {
                host="local"
            }

            print "last/wtmp\t" user "\t" host "\t" $0
        }
    ' || true
}

collect_from_journal() {
    if ! command -v journalctl >/dev/null 2>&1; then
        return 0
    fi

    journalctl --since today -o short-iso 2>/dev/null \
        | grep -Ei 'sshd.*Accepted|login.*session opened|systemd-logind.*New session' \
        | sed -E 's/[[:space:]]+/ /g' \
        | awk '
            /sshd.*Accepted/ {
                user="unknown"
                ip="unknown"

                for (i=1; i<=NF; i++) {
                    if ($i == "for") user=$(i+1)
                    if ($i == "from") ip=$(i+1)
                }

                print "journalctl\t" user "\t" ip "\t" $0
                next
            }

            {
                print "journalctl\tlocal\tlocal\t" $0
            }
        ' || true
}

collect_from_auth_logs() {
    local todayPattern
    todayPattern="$(LC_ALL=C date '+%b %e')"

    local files=()
    [[ -r /var/log/auth.log ]] && files+=("/var/log/auth.log")
    [[ -r /var/log/secure ]] && files+=("/var/log/secure")

    if [[ ${#files[@]} -eq 0 ]]; then
        return 0
    fi

    grep -h "$todayPattern" "${files[@]}" 2>/dev/null \
        | grep -Ei 'sshd.*Accepted|login.*session opened' \
        | sed -E 's/[[:space:]]+/ /g' \
        | awk '
            /sshd.*Accepted/ {
                user="unknown"
                ip="unknown"

                for (i=1; i<=NF; i++) {
                    if ($i == "for") user=$(i+1)
                    if ($i == "from") ip=$(i+1)
                }

                print "authlog\t" user "\t" ip "\t" $0
                next
            }

            {
                print "authlog\tlocal\tlocal\t" $0
            }
        ' || true
}

collect_today() {
    {
        collect_from_last
        collect_from_journal
        collect_from_auth_logs
    } | awk '!seen[$0]++'
}

build_report() {
    local host="$1"
    local now="$2"
    local publicIp="$3"
    local records="$4"

    local summary
    summary="$(printf '%s\n' "$records" \
        | awk -F '\t' '$3 != "" { count[$3]++; last[$3]=$4 } END { for (ip in count) print ip "\t" count[ip] "\t" last[ip] }' \
        | sort -k2,2nr -k1,1)"

    {
        echo "Linux login IP report"
        echo "Host: $host"
        echo "Server public IP: ${publicIp:-unavailable}"
        echo "Time: $now"
        echo
        echo "Today's successful login IPs:"

        if [[ -z "${summary// }" ]]; then
            echo "  none"
        else
            printf '%s\n' "$summary" | awk -F '\t' '{ printf "  %s (%s times)\n", $1, $2 }'
        fi

        echo
        echo "Records:"

        if [[ -z "${records// }" ]]; then
            echo "  No successful login records found today."
        else
            printf '%s\n' "$records"
        fi
    }
}

run_today() {
    require_webhook

    local host
    host="$(hostname -f 2>/dev/null || hostname)"
    local now
    now="$(date '+%F %T %Z')"
    local publicIp
    publicIp="$(get_public_ip || true)"
    local records
    records="$(collect_today || true)"
    local report
    report="$(build_report "$host" "$now" "$publicIp" "$records")"

    send_feishu_text "$report"
    echo "Feishu login report sent."
}

quote_systemd_env_value() {
    printf '"%s"' "$(printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g')"
}

install_timer() {
    if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
        echo "Install requires root. Run with sudo." >&2
        exit 1
    fi

    if ! command -v systemctl >/dev/null 2>&1; then
        echo "systemd is required for --install." >&2
        exit 1
    fi

    require_webhook

    mkdir -p "$InstallDir"

    local target="$InstallDir/linux-login-ip-feishu-report.sh"
    local sourcePath
    sourcePath="$(readlink -f "$0" 2>/dev/null || printf '%s\n' "$0")"
    local targetPath
    targetPath="$(readlink -m "$target" 2>/dev/null || printf '%s\n' "$target")"

    if [[ "$sourcePath" != "$targetPath" ]]; then
        cp "$0" "$target"
        chmod 755 "$target"
    fi

    {
        printf 'FEISHU_WEBHOOK_URL=%s\n' "$(quote_systemd_env_value "$WebhookUrl")"
        printf 'WLIFF_INSTALL_DIR=%s\n' "$(quote_systemd_env_value "$InstallDir")"
    } > "$EnvFile"
    chmod 600 "$EnvFile"

    cat > "/etc/systemd/system/${ServiceName}.service" <<EOF
[Unit]
Description=Send Linux successful login IP report to Feishu

[Service]
Type=oneshot
EnvironmentFile=$EnvFile
ExecStart=$target --today
EOF

    cat > "/etc/systemd/system/${ServiceName}.timer" <<EOF
[Unit]
Description=Run Linux login IP Feishu report daily

[Timer]
OnCalendar=*-*-* $TaskTime:00
Persistent=true

[Install]
WantedBy=timers.target
EOF

    systemctl daemon-reload
    systemctl enable --now "${ServiceName}.timer"

    "$target" --today

    echo "Installed script: $target"
    echo "Installed timer: ${ServiceName}.timer"
}

case "$Mode" in
    --today|-Today)
        run_today
        ;;
    --install|-InstallTask)
        install_timer
        ;;
    --help|-h)
        usage
        ;;
    *)
        usage >&2
        exit 1
        ;;
esac
