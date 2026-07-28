#!/usr/bin/env bash

set -u

LOG="/var/log/nginx/access.log"
TMP="$(mktemp)"
trap 'rm -f "$TMP"' EXIT

if [ ! -r "$LOG" ]; then
    echo "[ERROR] Cannot read $LOG"
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
echo "=============================================="
echo " MONITOR COMPLETE"
echo "=============================================="
