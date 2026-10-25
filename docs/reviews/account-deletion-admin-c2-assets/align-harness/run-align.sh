cd /Users/pedram/Desktop/PROJECT/Catalyst-Engine/apps/main
export PLAYWRIGHT_BROWSERS_PATH=$HOME/Library/Caches/ms-playwright
(npx vite --config .tmp-align/vite.config.ts > /tmp/align-vite.log 2>&1 & echo $! > /tmp/align-vite.pid)
sleep 5
for tag in "$@"; do
  node measure-tmp.mjs $tag-native "" 2>&1 | head -3; python3 /tmp/ink.py $tag-native
  node measure-tmp.mjs $tag-web "?web" 2>&1 | head -3; python3 /tmp/ink.py $tag-web
done
kill $(cat /tmp/align-vite.pid) 2>/dev/null; pkill -f "align/vite.config"; true
