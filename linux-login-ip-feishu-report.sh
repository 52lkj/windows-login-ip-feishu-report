#!/usr/bin/env bash
set -euo pipefail

WebhookUrl="${FEISHU_WEBHOOK_URL:-}"
BackendUrl="${WLIFF_BACKEND_URL:-}"
IngestToken="${WLIFF_INGEST_TOKEN:-}"
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
  WLIFF_BACKEND_URL     Optional backend ingest URL, for example https://example.com/web/ingest.php.
  WLIFF_INGEST_TOKEN    Bearer token for backend ingest.
  WLIFF_INSTALL_DIR    Install directory. Default: /opt/windows-login-ip-feishu-report
  WLIFF_TASK_TIME      Daily timer time in HH:mm. Default: 18:00
EOF
}

require_delivery_target() {
    if [[ -z "${WebhookUrl// }" && -z "${BackendUrl// }" ]]; then
        echo "FEISHU_WEBHOOK_URL or WLIFF_BACKEND_URL is required." >&2
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

send_backend_payload() {
    local payload="$1"

    if [[ -z "${BackendUrl// }" ]]; then
        return 0
    fi

    if [[ -z "${IngestToken// }" ]]; then
        echo "WLIFF_INGEST_TOKEN is required when WLIFF_BACKEND_URL is set." >&2
        exit 1
    fi

    if command -v curl >/dev/null 2>&1; then
        curl -fsS \
            -H "Content-Type: application/json; charset=utf-8" \
            -H "Authorization: Bearer $IngestToken" \
            -d "$payload" \
            "$BackendUrl" >/dev/null
    elif command -v wget >/dev/null 2>&1; then
        wget -qO- \
            --header="Content-Type: application/json; charset=utf-8" \
            --header="Authorization: Bearer $IngestToken" \
            --post-data="$payload" \
            "$BackendUrl" >/dev/null
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

    local mode="${1:-all}"

    LC_ALL=C last -iF -w -s today 2>/dev/null | awk -v mode="$mode" '
        /^wtmp begins/ { next }
        /^reboot/ { next }
        /^shutdown/ { next }
        /^$/ { next }
        {
            user=$1
            tty=$2
            host=$3
            timeText=""

            if (host == "" || host == ":0" || host == "0.0.0.0" || host == "-" || host ~ /^(Mon|Tue|Wed|Thu|Fri|Sat|Sun)$/) {
                host="local"
            }

            if (mode == "local-only" && host != "local") {
                next
            }

            if (match($0, /(Mon|Tue|Wed|Thu|Fri|Sat|Sun) [A-Z][a-z][a-z] +[0-9]+ [0-9:]+ [0-9]{4}/)) {
                timeText=substr($0, RSTART, RLENGTH)
            }

            print "last/wtmp\t" user "\t" host "\t" tty "\tlogin\t" timeText
        }
    ' || true
}

collect_from_journal() {
    if ! command -v journalctl >/dev/null 2>&1; then
        return 0
    fi

    journalctl --since today -o short-iso 2>/dev/null \
        | grep -Ei 'sshd.*Accepted' \
        | sed -E 's/[[:space:]]+/ /g' \
        | awk '
            /sshd.*Accepted/ {
                user="unknown"
                ip="unknown"

                for (i=1; i<=NF; i++) {
                    if ($i == "for") user=$(i+1)
                    if ($i == "from") ip=$(i+1)
                }

                print "journalctl\t" user "\t" ip "\tssh\tssh\t" $1
                next
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
        | grep -Ei 'sshd.*Accepted' \
        | sed -E 's/[[:space:]]+/ /g' \
        | awk '
            /sshd.*Accepted/ {
                user="unknown"
                ip="unknown"

                for (i=1; i<=NF; i++) {
                    if ($i == "for") user=$(i+1)
                    if ($i == "from") ip=$(i+1)
                }

                timeText=$1 " " $2 " " $3
                print "authlog\t" user "\t" ip "\tssh\tssh\t" timeText
                next
            }
        ' || true
}

collect_today() {
    local primaryRecords
    primaryRecords="$(collect_from_journal | awk '!seen[$0]++')"

    if [[ -z "${primaryRecords// }" ]]; then
        primaryRecords="$(collect_from_auth_logs | awk '!seen[$0]++')"
    fi

    local lastMode="all"
    if printf '%s\n' "$primaryRecords" | awk -F '\t' '$3 != "" && $3 != "local" && $3 != "unknown" { found=1 } END { exit found ? 0 : 1 }'; then
        lastMode="local-only"
    fi

    if [[ -n "${primaryRecords// }" ]]; then
        printf '%s\n' "$primaryRecords"
    fi

    collect_from_last "$lastMode" | awk '!seen[$0]++'
}

build_report() {
    local host="$1"
    local now="$2"
    local publicIp="$3"
    local records="$4"

    local remoteSummary
    remoteSummary="$(printf '%s\n' "$records" \
        | awk -F '\t' '$3 != "" && $3 != "local" && $3 != "unknown" { count[$3]++ } END { for (ip in count) print ip "\t" count[ip] }' \
        | sort -k2,2nr -k1,1)"

    local localCount
    localCount="$(printf '%s\n' "$records" | awk -F '\t' '$3 == "local" { count++ } END { print count + 0 }')"

    local remoteCount
    remoteCount="$(printf '%s\n' "$records" | awk -F '\t' '$3 != "" && $3 != "local" && $3 != "unknown" { count++ } END { print count + 0 }')"

    {
        echo "Linux 登录报告"
        echo "服务器: $host"
        echo "公网 IP: ${publicIp:-unavailable}"
        echo "生成时间: $now"
        echo
        echo "概览:"
        echo "  远程登录: $remoteCount 次"
        echo "  本地会话: $localCount 次"
        echo
        echo "远程 IP:"

        if [[ -z "${remoteSummary// }" ]]; then
            echo "  无"
        else
            printf '%s\n' "$remoteSummary" | awk -F '\t' '{ printf "  %s  %s 次\n", $1, $2 }'
        fi

        echo
        echo "最近明细:"

        if [[ -z "${records// }" ]]; then
            echo "  今日没有成功登录记录。"
        else
            printf '%s\n' "$records" \
                | awk -F '\t' '
                    NR <= 20 {
                        source=$1
                        user=$2
                        ip=$3
                        channel=$4
                        method=$5
                        timeText=$6

                        if (timeText == "") {
                            timeText="unknown time"
                        }

                        if (ip == "local") {
                            printf "  %s  本地  user=%s  type=%s  source=%s\n", timeText, user, channel, source
                        } else {
                            printf "  %s  %s  user=%s  method=%s  source=%s\n", timeText, ip, user, method, source
                        }
                    }
                    END {
                        if (NR > 20) {
                            printf "  ... 还有 %d 条\n", NR - 20
                        }
                    }
                '
        fi
    }
}

build_backend_payload() {
    local host="$1"
    local publicIp="$2"
    local records="$3"

    if command -v python3 >/dev/null 2>&1; then
        local recordsFile
        recordsFile="$(mktemp)"
        printf '%s\n' "$records" > "$recordsFile"

        HOST="$host" PUBLIC_IP="$publicIp" RECORDS_FILE="$recordsFile" python3 - <<'PY'
import json
import os
import socket

host = os.environ.get("HOST") or socket.gethostname()
public_ip = os.environ.get("PUBLIC_IP") or None
records_file = os.environ.get("RECORDS_FILE")
records = ""
if records_file:
    with open(records_file, "r", encoding="utf-8") as handle:
        records = handle.read()
events = []

for line in records.splitlines():
    parts = line.split("\t")
    if len(parts) < 6:
        continue
    source, username, login_ip, channel, method, occurred_at = parts[:6]
    events.append({
        "source": source,
        "username": username,
        "login_ip": login_ip,
        "channel": channel,
        "method": method,
        "occurred_at": occurred_at,
        "is_success": True,
    })

print(json.dumps({
    "server_key": host,
    "hostname": host,
    "server_public_ip": public_ip,
    "events": events,
}, ensure_ascii=False, separators=(",", ":")))
PY
        local status=$?
        rm -f "$recordsFile"
        return "$status"
    else
        echo "python3 is required for backend JSON payload generation." >&2
        exit 1
    fi
}

run_today() {
    require_delivery_target

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

    if [[ -n "${WebhookUrl// }" ]]; then
        send_feishu_text "$report"
        echo "Feishu login report sent."
    fi

    if [[ -n "${BackendUrl// }" ]]; then
        local payload
        payload="$(build_backend_payload "$host" "$publicIp" "$records")"
        send_backend_payload "$payload"
        echo "Backend login report sent."
    fi
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

    require_delivery_target

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
        printf 'WLIFF_BACKEND_URL=%s\n' "$(quote_systemd_env_value "$BackendUrl")"
        printf 'WLIFF_INGEST_TOKEN=%s\n' "$(quote_systemd_env_value "$IngestToken")"
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
