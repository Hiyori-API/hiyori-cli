#!/usr/bin/env python3
"""Level-0 local embedding sidecar for the semantic_rerank combiner strategy.

Protocol
--------
Reads one JSON object per line on stdin::

    {"text": "Cowboy Bebop"}

Writes one JSON array of floats per line on stdout. The model is loaded once
per process. `EmbeddingService` starts the process per title (cheap for the
small batches used by the combiner) and caches every vector in
`storage/state/ml-embeddings.json`.

Install (free, local only)::

    pip install --user sentence-transformers
    export ML_EMBED_COMMAND='python3 /path/to/services/ml/embed.py'

If sentence-transformers is unavailable the script exits non-zero and the PHP
service transparently falls back to its deterministic n-gram hasher.
"""
from __future__ import annotations

import json
import sys

MODEL_NAME = "sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2"
_model = None


def get_model():
    global _model
    if _model is None:
        from sentence_transformers import SentenceTransformer  # type: ignore

        _model = SentenceTransformer(MODEL_NAME)
    return _model


def main() -> int:
    for line in sys.stdin:
        line = line.strip()
        if not line:
            continue
        try:
            payload = json.loads(line)
        except json.JSONDecodeError:
            continue
        text = str(payload.get("text", ""))
        try:
            vector = get_model().encode(text, normalize_embeddings=True)
        except Exception as exc:  # pragma: no cover - environment dependent
            print(f"embedding failed: {exc}", file=sys.stderr)
            return 1
        print(json.dumps([float(x) for x in vector.tolist()]), flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
