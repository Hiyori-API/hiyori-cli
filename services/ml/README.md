# ML sidecar (Level-0, local, free)

`sentence-transformers` running locally. No API keys, no network calls at
runtime; the model is downloaded once when you first load it.

## Setup

```sh
python3 -m venv .venv-ml
. .venv-ml/bin/activate
pip install -r services/ml/requirements.txt

export ML_EMBED_COMMAND="python3 $PWD/services/ml/embed.py"
export ML_MODEL="sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2"   # optional

# Smoke test: reads nothing, exits 0, prints nothing.
python3 services/ml/embed.py </dev/null
```

Then run the semantic combiner:

```sh
php hiyori combine anilist --strategy=semantic_rerank --into=organized
```

Without `ML_EMBED_COMMAND`, the combiner uses the deterministic n-gram hasher
in `src/Service/Ml/EmbeddingService.php` — fully offline and dependency-free.

## Model + cache

- `ML_MODEL` selects the sentence-transformers model (default
  `paraphrase-multilingual-MiniLM-L12-v2`, multilingual and small).
- `ML_EMBED_VERSION` stamps the embedding "version" (default `sidecar-1`);
  bump it when you change preprocessing so cached vectors are invalidated.
- Vectors are cached in `storage/ml/embeddings.json`, keyed by
  `(model, version, sha1(text))`. The cache records the model/version it was
  built with, so switching `ML_MODEL` discards stale vectors instead of mixing
  incompatible embeddings.
