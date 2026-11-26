#!/usr/bin/env bash

set -u

# مسیر لاگ: آرگومان اول، وگرنه از مسیرهای متعارف سرور پیدا می‌شود
LOG="${1:-}"
if [ -z "$LOG" ]; then
    for cand in /var/log/nginx/swaapin.access.log /var/log/nginx/access.log; do
        if [ -r "$cand" ]; then LOG="$cand"; break; fi
    done
fi
ERROR_LOG="${ERROR_LOG:-$(dirname "$LOG")/$(basename "$LOG" | sed 's/access/error/')}"
TMP="$(mktemp)"
trap 'rm -f "$TMP"' EXIT

if [ ! -r "$LOG" ]; then
    echo "[ERROR] Cannot read log file (pass it as \$1). Tried: ${LOG:-<none>}"
    exit 2
fi

if ! command -v host >/dev/null 2>&1; then
    echo "[ERROR] host command not found"
    exit 2
fi

verify_google_ip() {
    local ip="$1"
    local hostname
    local forward

    hostname=$(
        host "$ip" 2>/dev/null |
        awk '/pointer/ {print $NF}' |
        sed 's/\.$//' |
        head -n1
    )

    [ -z "$hostname" ] && return 1

    case "$hostname" in
        *.googlebot.com|*.google.com|*.googleusercontent.com)
            ;;
        *)
            return 1
            ;;
    esac

    forward=$(
        host "$hostname" 2>/dev/null |
        awk '/has address/ {print $NF}'
    )

    echo "$forward" | grep -Fxq "$ip"
}

echo "=============================================="
echo " SWAAPIN VERIFIED GOOGLEBOT SEO MONITOR"
echo " $(date)"
echo "=============================================="
echo

grep -i 'Googlebot' "$LOG" > "$TMP" || true

if [ ! -s "$TMP" ]; then
    echo "[INFO] No Googlebot User-Agent requests found."
    exit 0
fi

mapfile -t ips < <(
    awk '{print $1}' "$TMP" |
    sort -u
)

verified_ips=()
fake_ips=()

for ip in "${ips[@]}"; do
    if verify_google_ip "$ip"; then
        verified_ips+=("$ip")
    else
        fake_ips+=("$ip")
    fi
done

echo "========== VERIFIED GOOGLE IPS =========="
if [ "${#verified_ips[@]}" -eq 0 ]; then
    echo "[NONE]"
else
    printf '%s\n' "${verified_ips[@]}"
fi

echo
echo "========== SPOOFED / UNVERIFIED GOOGLEBOT IPS =========="
if [ "${#fake_ips[@]}" -eq 0 ]; then
    echo "[NONE]"
else
    printf '%s\n' "${fake_ips[@]}"
fi

echo
echo "========== VERIFIED GOOGLEBOT 404s =========="

found_404=0

for ip in "${verified_ips[@]}"; do
    while IFS= read -r line; do
        status=$(echo "$line" | awk -F'"' '{print $3}' | awk '{print $1}')
        path=$(echo "$line" | awk -F'"' '{print $2}' | awk '{print $2}')

        if [ "$status" = "404" ]; then
            printf '%s %s %s\n' "$ip" "$status" "$path"
            found_404=1
        fi
    done < <(grep "^$ip " "$TMP")
done

[ "$found_404" -eq 0 ] && echo "[PASS] No verified Googlebot 404 responses found"

echo
echo "========== VERIFIED GOOGLEBOT 5xx =========="

found_5xx=0

for ip in "${verified_ips[@]}"; do
    while IFS= read -r line; do
        status=$(echo "$line" | awk -F'"' '{print $3}' | awk '{print $1}')
        path=$(echo "$line" | awk -F'"' '{print $2}' | awk '{print $2}')

        case "$status" in
            5??)
                printf '%s %s %s\n' "$ip" "$status" "$path"
                found_5xx=1
                ;;
        esac
    done < <(grep "^$ip " "$TMP")
done

[ "$found_5xx" -eq 0 ] && echo "[PASS] No verified Googlebot 5xx responses found"

echo
echo "========== VERIFIED GOOGLEBOT STATUS SUMMARY =========="

for ip in "${verified_ips[@]}"; do
    grep "^$ip " "$TMP"
done |
awk -F'"' '
{
    split($3,a," ")
    status=a[1]
    if (status != "") count[status]++
}
END {
    for (s in count)
        print s, count[s]
}
' | sort

echo
echo "========== VERIFIED GOOGLEBOT 5xx OUTAGE WINDOWS (PER MINUTE) =========="

awk -F'"' '
{
    split($3, s, " ")
    if (s[1] ~ /^5[0-9][0-9]$/) {
        ts = $1
        sub(/^.*\[/, "", ts)
        split(ts, t, ":")
        c[t[1] " " t[2] ":" t[3]]++
    }
}
END {
    if (length(c) == 0) { print "[PASS] No 5xx responses for Googlebot"; exit }
    for (k in c) print c[k], k
}
' "$TMP" 2>/dev/null | sort -nr | head -20

echo
echo "========== BOT-BLOCK SIGNALS (403/429 for Googlebot) =========="

awk -F'"' '
/Googlebot/ {
    split($3, s, " ")
    if (s[1] == "403" || s[1] == "429") print s[1], $2
}
' "$TMP" 2>/dev/null | sort | uniq -c | sort -nr | head -20
awk -F'"' '/Googlebot/ { split($3,s," "); if (s[1]=="403" || s[1]=="429") n++ } END { if (n==0) print "[PASS] No 403/429 (no bot throttling/blocking)" }' "$TMP"

echo
echo "========== NGINX ERROR-LOG SIGNALS =========="

if [ -r "$ERROR_LOG" ]; then
    echo "-- top signals --"
    grep -Eio 'upstream timed out|connect\(\) failed|no live upstreams|worker_connections are not enough|too many open files|recv\(\) failed|upstream prematurely closed connection' "$ERROR_LOG" 2>/dev/null |
        sort | uniq -c | sort -nr | head -20
    echo "-- last 10 raw error lines --"
    tail -n 10 "$ERROR_LOG"
else
    echo "[SKIP] $ERROR_LOG not readable"
fi

echo
echo "========== FIREWALL / RATE-LIMIT AUDIT =========="

if command -v ufw >/dev/null 2>&1; then
    echo "-- ufw --"
    ufw status verbose 2>/dev/null || echo "[SKIP] ufw needs root"
fi

if command -v fail2ban-client >/dev/null 2>&1; then
    echo "-- fail2ban jails --"
    fail2ban-client status 2>/dev/null || echo "[SKIP] fail2ban needs root"
fi

echo "-- nginx rules that could throttle/deny bots --"
grep -RnE 'limit_req|limit_conn|deny[[:space:]]|return 429' /etc/nginx/ 2>/dev/null | grep -vE ':[[:space:]]*#' | head -20 || true
echo "[NOTE] Googlebot must NOT be inside any limit_req/deny rule."

echo
echo "=============================================="
echo " MONITOR COMPLETE"
echo "=============================================="
