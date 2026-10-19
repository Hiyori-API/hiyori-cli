#!/usr/bin/env python3
"""Measure what the local embedding sidecar really costs on this machine.

The numbers that decide whether an embedding strategy is viable are not the
model's benchmark score — they are the wall-clock cost of turning a title
into a vector, here, on this box.

Three costs are reported:

1. **Model load** — the one-off cost of importing the model and reading it
   from disk (or the HF cache).
2. **Per-text latency** — a warm model encoding one title at a time, which
   is what the sidecar protocol does per line.
3. **Process-per-text** — the cost the PHP service actually pays today:
   `EmbeddingService::embedWithCommand()` starts a fresh `embed.py` for
   every text, so every embedding pays the model load again. The sidecar
   protocol supports a warm process (one JSON line in, one JSON array out,
   for as many lines as you like), but the PHP side does not use it yet.

Usage::

    .venv-ml/bin/python services/ml/bench_embed.py \
        --model intfloat/multilingual-e5-small \
        --titles /tmp/titles.txt --limit 200
"""
from __future__ import annotations

import argparse
import json
import os
import statistics
import subprocess
import sys
import time
from pathlib import Path


def percentile(values: list[float], pct: float) -> float:
    """Nearest-rank percentile; no interpolation, so it is a real sample."""
    if not values:
        return 0.0
    ordered = sorted(values)
    rank = max(1, min(len(ordered), int(round(pct / 100.0 * len(ordered) + 0.5))))
    return ordered[rank - 1]


def read_titles(path: str, limit: int) -> list[str]:
    titles: list[str] = []
    with open(path, encoding="utf-8") as handle:
        for line in handle:
            line = line.strip()
            if line:
                titles.append(line)
            if len(titles) >= limit:
                break
    return titles


def model_size_on_disk(model: str) -> tuple[int, int]:
    """Bytes the model occupies in the Hugging Face cache.

    Returns (weights bytes, total cache bytes). The cache stores the
    weights as hash-named blobs, so the weights are the largest single
    file. Symlinks are not followed, or the snapshot links would count the
    blobs twice.
    """
    hub = Path(os.environ.get("HF_HOME", Path.home() / ".cache" / "huggingface")) / "hub"
    total = 0
    largest = 0
    for path in hub.glob(f"models--{model.replace('/', '--')}*"):
        if not path.is_dir():
            continue
        for file in path.rglob("*"):
            if file.is_symlink() or not file.is_file():
                continue
            size = file.stat().st_size
            total += size
            largest = max(largest, size)
    return largest, total


def bench_warm(model_name: str, titles: list[str]) -> dict[str, float]:
    from sentence_transformers import SentenceTransformer  # type: ignore

    start = time.perf_counter()
    model = SentenceTransformer(model_name)
    load_seconds = time.perf_counter() - start

    # Warm the graph once so the first measurement is not an outlier.
    model.encode(titles[0], normalize_embeddings=True)

    latencies: list[float] = []
    for title in titles:
        start = time.perf_counter()
        model.encode(title, normalize_embeddings=True)
        latencies.append((time.perf_counter() - start) * 1000.0)

    dims = len(model.encode(titles[0], normalize_embeddings=True))
    return {
        "load_seconds": load_seconds,
        "latencies_ms": latencies,
        "dimensions": dims,
    }


def bench_process_per_text(model_name: str, titles: list[str], sidecar: str) -> list[float]:
    """The cost the PHP service pays: one process per text."""
    latencies: list[float] = []
    for title in titles:
        start = time.perf_counter()
        proc = subprocess.run(
            [sys.executable, sidecar],
            input=json.dumps({"text": title, "model": model_name}) + "\n",
            capture_output=True,
            text=True,
        )
        latencies.append((time.perf_counter() - start) * 1000.0)
        if proc.returncode != 0:
            print(f"sidecar failed: {proc.stderr[:400]}", file=sys.stderr)
            break
    return latencies


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--model", default=os.environ.get("ML_MODEL", "intfloat/multilingual-e5-small"))
    parser.add_argument("--titles", required=True, help="File with one title per line")
    parser.add_argument("--limit", type=int, default=200)
    parser.add_argument("--sidecar", default=str(Path(__file__).resolve().parent / "embed.py"))
    parser.add_argument("--skip-process-per-text", action="store_true")
    args = parser.parse_args()

    titles = read_titles(args.titles, args.limit)
    if not titles:
        print("no titles", file=sys.stderr)
        return 1

    print(f"model:  {args.model}")
    print(f"titles: {len(titles)}")

    warm = bench_warm(args.model, titles)
    latencies = warm["latencies_ms"]
    print(f"dimensions: {int(warm['dimensions'])}")
    print(f"model load: {warm['load_seconds']:.2f} s")
    print(
        "warm per-text latency: median {med:.1f} ms  p95 {p95:.1f} ms  mean {mean:.1f} ms  max {mx:.1f} ms".format(
            med=statistics.median(latencies),
            p95=percentile(latencies, 95),
            mean=statistics.fmean(latencies),
            mx=max(latencies),
        )
    )
    weights_bytes, cache_bytes = model_size_on_disk(args.model)
    print(f"model weights on disk: {weights_bytes / (1024 * 1024):.1f} MiB")
    print(f"model cache on disk: {cache_bytes / (1024 * 1024):.1f} MiB")

    if not args.skip_process_per_text:
        cold = bench_process_per_text(args.model, titles, args.sidecar)
        if cold:
            print(
                "process-per-text (as EmbeddingService calls it): median {med:.1f} ms  p95 {p95:.1f} ms".format(
                    med=statistics.median(cold),
                    p95=percentile(cold, 95),
                )
            )

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
