#!/bin/bash
# Reads stdin JSON from Claude Code's PreToolUse hook.
# If the bash command contains "git commit", bumps version.php and stages it.

COMMAND=$(jq -r '.tool_input.command // empty')

echo "$COMMAND" | grep -q 'git commit' || exit 0

GIT_ROOT=$(git -C /home/user/moodle-local_grpcalendarimport rev-parse --show-toplevel 2>/dev/null)
VERSION_FILE="$GIT_ROOT/version.php"

[ -f "$VERSION_FILE" ] || exit 0

python3 - <<'PYEOF'
import re, datetime, sys, os

git_root = os.popen("git -C /home/user/moodle-local_grpcalendarimport rev-parse --show-toplevel").read().strip()
version_file = os.path.join(git_root, "version.php")

with open(version_file, 'r') as f:
    content = f.read()

match = re.search(r'(\$plugin->version\s*=\s*)(\d{10})', content)
if not match:
    sys.exit(0)

current = match.group(2)
today = datetime.date.today().strftime('%Y%m%d')

if current[:8] == today:
    build = int(current[8:]) + 1
else:
    build = 1

new_ver = f'{today}{build:02d}'
prefix = match.group(1)
new_content = re.sub(r'(\$plugin->version\s*=\s*)\d{10}', prefix + new_ver, content)

with open(version_file, 'w') as f:
    f.write(new_content)

print(f"Version bumped: {current} → {new_ver}")
PYEOF

# Stage the bumped version.php so it's included in the commit
git -C "$GIT_ROOT" add version.php
