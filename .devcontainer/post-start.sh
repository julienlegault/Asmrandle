#!/bin/bash

echo "🎮 Starting Asmrandle development server..."

# Check if server is already running
if pgrep -f "python -m http.server 8000" > /dev/null; then
  echo "✅ Web server already running"
else
  # Start Python HTTP server in background, fully detached
  cd /workspaces/Asmrandle
  nohup python -m http.server 8000 > /tmp/http-server.log 2>&1 &
  SERVER_PID=$!
  
  # Wait a moment for the server to start
  sleep 1
  
  # Verify the server is running
  if pgrep -f "python -m http.server 8000" > /dev/null; then
    echo "✅ Web server started (PID: $SERVER_PID)"
  else
    echo "❌ Failed to start web server. Check /tmp/http-server.log"
    cat /tmp/http-server.log
    exit 1
  fi
fi

echo "📍 Server available at: http://localhost:8000"
echo ""
echo "🧪 Ready for E2E testing with:"
echo "   npm test"
echo ""
