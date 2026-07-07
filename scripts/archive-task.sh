#!/usr/bin/env bash
# scripts/archive-task.sh
set -euo pipefail
TASK_ID="$1"
cd .agents

# Pull task out of active list, append to archive
jq --arg id "$TASK_ID" '.tasks[] | select(.id==$id)' tasks.json >> tasks.archive.jsonl
jq --arg id "$TASK_ID" '.tasks |= map(select(.id != $id))' tasks.json > tasks.tmp
mv tasks.tmp tasks.json

# Move progress section — handles both ## header and plain-text section formats
if grep -q "$TASK_ID" progress.md 2>/dev/null; then
    echo "## Archived $(date +%Y-%m-%d): $TASK_ID" >> progress.archive.md
    python3 - "$TASK_ID" progress.md progress.archive.md <<'PYEOF'
import sys, re

task_id   = sys.argv[1]
prog_file = sys.argv[2]
arch_file = sys.argv[3]

content = open(prog_file).read()
lines   = content.splitlines(keepends=True)

# Find start of the task section (line that starts with or contains task_id as a header)
start = None
for i, line in enumerate(lines):
    stripped = line.strip()
    if stripped == task_id or stripped.startswith(task_id + ' ') or stripped.startswith('## ' + task_id):
        start = i
        break

if start is None:
    sys.exit(0)  # not found, nothing to do

# Find end: next section (line starting with 'task-' or '## ') or EOF
end = len(lines)
for i in range(start + 1, len(lines)):
    s = lines[i].strip()
    if s.startswith('task-') or s.startswith('## task-'):
        end = i
        break

# Write section to archive
with open(arch_file, 'a') as f:
    f.writelines(lines[start:end])
    f.write('\n')

# Write remainder back to progress.md
with open(prog_file, 'w') as f:
    f.writelines(lines[:start])
    f.writelines(lines[end:])
PYEOF
    echo "Progress section for $TASK_ID archived"
else
    echo "No progress section found for $TASK_ID — skipping progress archive"
fi

echo "Archived $TASK_ID"
