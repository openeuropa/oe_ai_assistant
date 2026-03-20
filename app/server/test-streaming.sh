#!/usr/bin/env bash
#
# Test progressive SSE streaming from the drafting endpoint.
# Shows every AG-UI event with a timestamp so you can verify
# that events arrive individually, not batched.
#
# Usage: ./server/test-streaming.sh [port]
#   port  Express server port (default: 5150)

PORT="${1:-5150}"
URL="http://localhost:${PORT}/api/plugins/drafting/chat"

echo "Sending draft request to ${URL}..."
echo "---"

curl -sS --no-buffer -X POST "$URL" \
  -H "Content-Type: application/json" \
  -d '{"message":"Please call the draft_content tool now to generate a short news article about space exploration","bundle":"oe_news"}' \
  2>&1 | while IFS= read -r line; do
    case "$line" in
      data:*)
        echo "$(date +%H:%M:%S.%3N)  ${line#data: }"
        ;;
    esac
  done

echo "---"
echo "Done."
