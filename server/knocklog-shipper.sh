#!/bin/sh
# Knocklog log shipper for Linux servers: Nginx, Apache, Caddy in combined format (https://knocklog.ai)
#
# Every few minutes (cron) it sends the new lines of your access logs to Knocklog. Needs
# only sh, curl and the usual tools; it does not touch your site.
#
# Install:
#   sudo install -m 750 knocklog-shipper.sh /usr/local/bin/knocklog-shipper
#   sudo knocklog-shipper --check                        # finds the logs and tests the key
#   echo '*/5 * * * * root /usr/local/bin/knocklog-shipper' | sudo tee /etc/cron.d/knocklog
#
# Logs: pass paths as arguments, or it uses the first default that exists
# (/var/log/nginx/access.log, /var/log/apache2/access.log, /var/log/httpd/access_log).
# Where it stopped is kept in $KNOCKLOG_STATE_DIR (default /var/lib/knocklog).

KNOCKLOG_ENDPOINT='https://api.knocklog.ai'
# Ingest-only key: it can send visits, it cannot read your data.
KNOCKLOG_KEY='kl_ing_YOUR_INGEST_KEY'
STATE_DIR="${KNOCKLOG_STATE_DIR:-/var/lib/knocklog}"
MAX_BYTES=1500000
MAX_LINES=5000
MAX_POSTS=40

set -u
check=0
if [ "${1:-}" = "--check" ]; then check=1; shift; fi
if [ "$#" -eq 0 ]; then
  for f in /var/log/nginx/access.log /var/log/apache2/access.log /var/log/httpd/access_log; do
    if [ -r "$f" ]; then set -- "$f"; break; fi
  done
fi
if [ "$#" -eq 0 ]; then
  echo "Knocklog: no readable access log found. Pass the log's path as an argument." >&2
  exit 1
fi

if [ "$check" -eq 1 ]; then
  for f in "$@"; do
    if [ -r "$f" ]; then echo "Knocklog: log $f ($(wc -c < "$f") bytes)"; else echo "Knocklog: cannot read $f" >&2; fi
  done
  code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 30 -H "Authorization: Bearer $KNOCKLOG_KEY" "$KNOCKLOG_ENDPOINT/v1/plan")
  if [ "$code" = "200" ]; then echo "Knocklog: the key works. Add the cron job and the data will show in your report within minutes."; exit 0; fi
  echo "Knocklog: the API answered $code" >&2
  exit 2
fi

mkdir -p "$STATE_DIR" || exit 1
# One run at a time.
if ! mkdir "$STATE_DIR/lock" 2>/dev/null; then exit 0; fi
tmp="$STATE_DIR/block.$$"
resp="$STATE_DIR/response.$$"
trap 'rm -rf "$STATE_DIR/lock" "$tmp" "$resp"' EXIT INT TERM

posts=0
status=0
for f in "$@"; do
  [ -r "$f" ] || { echo "Knocklog: cannot read $f" >&2; status=2; continue; }
  state="$STATE_DIR/$(printf '%s' "$f" | tr -c 'A-Za-z0-9._-' '_').state"
  inode=$(ls -i "$f" | awk '{print $1}')
  size=$(wc -c < "$f" | tr -d ' ')
  offset=0
  if [ -f "$state" ]; then
    read -r known_inode known_offset < "$state" || true
    # Rotated or truncated: start over.
    if [ "${known_inode:-}" = "$inode" ] && [ "${known_offset:-0}" -le "$size" ]; then offset=${known_offset:-0}; fi
  fi
  while [ "$posts" -lt "$MAX_POSTS" ] && [ "$offset" -lt "$size" ]; do
    tail -c +"$((offset + 1))" "$f" | head -c "$MAX_BYTES" | head -n "$MAX_LINES" > "$tmp"
    bytes=$(wc -c < "$tmp" | tr -d ' ')
    [ "$bytes" -gt 0 ] || break
    if [ "$(tail -c 1 "$tmp" | od -An -tx1 | tr -d ' \n')" != "0a" ]; then
      # Keep whole lines only; a partial last line is still being written.
      if [ "$(wc -l < "$tmp" | tr -d ' ')" -eq 0 ]; then
        # No newline at all in a full block: an impossible line, skip it.
        if [ "$bytes" -ge "$MAX_BYTES" ]; then offset=$((offset + bytes)); continue; fi
        break
      fi
      sed '$d' "$tmp" > "$tmp.whole" && mv "$tmp.whole" "$tmp"
      bytes=$(wc -c < "$tmp" | tr -d ' ')
    fi
    if grep -q '[^[:space:]]' "$tmp"; then
      code=$(curl -sS -o "$resp" -w '%{http_code}' --max-time 60 -X POST \
        -H "Authorization: Bearer $KNOCKLOG_KEY" -H 'Content-Type: text/plain' \
        -A 'KnocklogShipper/1.0 (+https://knocklog.ai)' --data-binary @"$tmp" "$KNOCKLOG_ENDPOINT/v1/logs?live=1")
      posts=$((posts + 1))
      if [ "$code" != "202" ]; then
        echo "Knocklog: the API answered $code: $(head -c 300 "$resp" 2>/dev/null)" >&2
        status=2
        break
      fi
    fi
    offset=$((offset + bytes))
  done
  printf '%s %s\n' "$inode" "$offset" > "$state"
  [ "$status" -eq 2 ] && [ "${code:-}" != "" ] && [ "${code:-}" != "202" ] && break
done
exit "$status"
