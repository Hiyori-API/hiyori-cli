# ML sidecar (Level-0, local, free)

`sentence-transformers` running locally. No API keys, no network calls at
runtime; the model is downloaded once when you install/cache it.

## Setup

```sh
python3 -m venv .venv-ml
. .venv-ml/bin/activate
pip install sentence-transformers
export ML_EMBED_COMMAND="$PWD/services/ml/embed.py"
```

Then run the semantic combiner:

```sh
php hiyori combine anilist --strategy=semantic_rerank --into=organized
```

Without `ML_EMBED_COMMAND`, the combiner uses the deterministic n-gram hasher
in `src/Service/Ml/EmbeddingService.php` — fully offline and dependency-free.
