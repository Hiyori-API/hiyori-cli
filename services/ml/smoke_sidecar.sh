#!/usr/bin/env bash
# Prove the sidecar protocol handles a stream of lines in ONE process:
# one JSON object per line in, one JSON array of floats per line out.
# This is the shape the PHP service does not use today.
set -euo pipefail
cd "$(dirname "$0")/../.."
VENV_PY=".venv-ml/bin/python"
[ -x "$VENV_PY" ] || { echo "no venv"; exit 1; }

{
  printf '{"text":"Cowboy Bebop"}\n'
  printf '{"text":"Berserk"}\n'
  printf '{"text":"Attack on Titan"}\n'
  printf '{"text":"進撃の巨人"}\n'
} > /tmp/sidecar-input.jsonl

/usr/bin/time -f "wall: %e s   peak RSS: %M KB" \
  "$VENV_PY" services/ml/embed.py < /tmp/sidecar-input.jsonl > /tmp/sidecar-output.jsonl

echo "lines in:  $(wc -l < /tmp/sidecar-input.jsonl)"
echo "lines out: $(wc -l < /tmp/sidecar-output.jsonl)"
"$VENV_PY" - <<'PY'
import json
for i, line in enumerate(open('/tmp/sidecar-output.jsonl'), 1):
    vec = json.loads(line)
    norm = sum(v * v for v in vec) ** 0.5
    print(f"  line {i}: {len(vec)} floats, L2 norm {norm:.6f}")
PY
