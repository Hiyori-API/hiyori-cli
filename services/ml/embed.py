#!/usr/bin/env python3
"""Level-0 local embedding sidecar for the semantic_rerank combiner strategy.

Protocol
--------
Reads one JSON object per line on stdin::

    {"text": "Cowboy Bebop", "model": "sentence-transformers/..."}

`model` is optional; when omitted the process default is used. The default can
be set with the `ML_MODEL` environment variable, otherwise it falls back to
`DEFAULT_MODEL` below. Writes one JSON array of floats per line on stdout.

Models are loaded lazily and cached per process, so a single sidecar instance
can serve several model names without reloading. `EmbeddingService` starts a
fresh process per title and caches vectors in
`storage/state/ml-embeddings.json` keyed by `(model, model_version, text)`.

Install (free, local only)::

    python3 -m venv .venv-ml && . .venv-ml/bin/activate
    pip install -r services/ml/requirements.txt
    export ML_EMBED_COMMAND="python3 $PWD/services/ml/embed.py"
    export ML_MODEL="sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2"

If sentence-transformers is unavailable the script exits non-zero and the PHP
service transparently falls back to its deterministic n-gram hasher.
"""
from __future__ import annotations

import json
import os
import sys

DEFAULT_MODEL = "sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2"

_models: dict[str, object] = {}


def get_model(name: str):
    if name not in _models:
        from sentence_transformers import SentenceTransformer  # type: ignore

        _models[name] = SentenceTransformer(name)
    return _models[name]


def main() -> int:
    default_model = os.environ.get("ML_MODEL") or DEFAULT_MODEL
    for line in sys.stdin:
        line = line.strip()
        if not line:
            continue
        try:
            payload = json.loads(line)
        except json.JSONDecodeError:
            continue
        text = str(payload.get("text", ""))
        model_name = str(payload.get("model") or default_model)
        try:
            vector = get_model(model_name).encode(text, normalize_embeddings=True)
        except Exception as exc:  # pragma: no cover - environment dependent
            print(f"embedding failed: {exc}", file=sys.stderr)
            return 1
        print(json.dumps([float(x) for x in vector.tolist()]), flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
