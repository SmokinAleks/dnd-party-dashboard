#!/bin/bash
# Double-click launcher for the D&D Party Dashboard dev server (macOS).
# Starts server.py in stub mode and opens the browser.

set -e
cd "$(dirname "$0")"

URL="http://localhost:8765/"

echo "════════════════════════════════════════════════"
echo "  ⚔  D&D Party Dashboard (dev server)"
echo "════════════════════════════════════════════════"
echo

# Start the dev server in the background.
python3 server.py &
SERVER_PID=$!

# Clean up if the terminal closes or Ctrl-C is hit.
trap "echo; echo 'Stopping server…'; kill $SERVER_PID 2>/dev/null; exit 0" INT TERM EXIT

# Give it a moment to bind, then open the browser.
sleep 1
if kill -0 $SERVER_PID 2>/dev/null; then
  open "$URL"
  echo
  echo "Dashboard running at: $URL"
  echo "Close this window to stop the server."
  echo
fi

wait $SERVER_PID
