# ML sidecar (Level-0, local, free)

`sentence-transformers` running locally. No API keys, no network calls at
runtime; the model is downloaded once when you first load it.

## Setup

```sh
python3 -m venv .venv-ml
# The CPU-only torch wheel first: the default PyPI wheel drags in the CUDA
# runtime (~2.5 GB) even on a box that will never use a GPU.
.venv-ml/bin/pip install --index-url https://download.pytorch.org/whl/cpu torch
.venv-ml/bin/pip install -r services/ml/requirements.txt

export ML_EMBED_COMMAND="$PWD/.venv-ml/bin/python $PWD/services/ml/embed.py"
export ML_MODEL="intfloat/multilingual-e5-small"
export ML_EMBED_VERSION="e5-small-1"

# Smoke test: reads nothing, exits 0, prints nothing.
.venv-ml/bin/python services/ml/embed.py </dev/null

# Smoke test with a real text: one JSON array of floats on stdout.
echo '{"text":"Cowboy Bebop"}' | .venv-ml/bin/python services/ml/embed.py

# Smoke test for the whole protocol (several lines, one warm process):
bash services/ml/smoke_sidecar.sh
```

The command must point at the venv interpreter. `python3` on `PATH` does not
have `sentence-transformers`, and the sidecar then exits non-zero — and
`EmbeddingService` silently falls back to its deterministic n-gram hasher, so
a broken sidecar looks like a working one. Check the exit code, and check
`storage/ml/embeddings.json` (its `model`/`version` fields) before trusting
an embedding result.

Then run the semantic combiner:

```sh
php hiyori combine anilist --strategy=semantic_rerank --into=organized
```

Without `ML_EMBED_COMMAND`, the combiner uses the deterministic n-gram hasher
in `src/Service/Ml/EmbeddingService.php` — fully offline and dependency-free.

## Model + cache

- `ML_MODEL` selects the sentence-transformers model. Recommended:
  - `intfloat/multilingual-e5-small` — 384 dims, MIT, multilingual
    (Japanese titles matter here), ONNX-exportable.
  - `Alibaba-NLP/gte-modernbert-base` — 768 dims, Apache-2.0.
- `ML_EMBED_VERSION` stamps the embedding "version" (default `sidecar-1`);
  bump it when you change the model or the preprocessing so cached vectors
  are invalidated.
- `ML_EMBED_PREFIX` prepends an instruction to the text. The e5 family is
  trained with one (`"query: "`, `"passage: "`); leave it unset for models
  that do not want one.
- Vectors are cached in `storage/ml/embeddings.json`, keyed by
  `(model, version, sha1(text))`. The cache records the model/version it was
  built with, so switching `ML_MODEL` discards stale vectors instead of
  mixing incompatible embeddings.

## Cost

`EmbeddingService::embedWithCommand()` starts a **fresh process per text**,
so every embedding pays the model load again. The protocol itself supports a
warm process (one JSON line in, one JSON array out, as many lines as you
like) — it is the PHP side that does not use it yet.

Measure the real numbers on your own box with:

```sh
.venv-ml/bin/python services/ml/bench_embed.py \
    --model intfloat/multilingual-e5-small --titles titles.txt --limit 200
```

It reports the model load time, the warm per-text latency (median and p95)
and the process-per-text cost the PHP service actually pays.

## Measured on this machine (2026-10-19, WSL2, 8 cores, no GPU)

`intfloat/multilingual-e5-small` (384 dims, MIT), 200 real titles from the
corpus:

| | |
|---|---|
| model load (`SentenceTransformer(...)`) | 5.98 s |
| `import sentence_transformers` alone | 6.1 s |
| warm per-text latency | median 28.0 ms, p95 62.7 ms |
| process-per-text (as `EmbeddingService` calls it) | median 12.7 s, p95 14.2 s |
| model weights on disk | 448.8 MiB (`model.safetensors`) |
| sidecar peak RSS | ~918 MiB |

The gap between 28 ms and 12.7 s is the whole story: a fresh process pays
the interpreter start, the torch import and the model load again for every
single title. The protocol already supports a warm process — feed it a line
per title and read a line back — so the fix is on the PHP side, not in the
model.
