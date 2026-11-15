"""
Minimal authenticated internal API around the official Mem0 OSS Python SDK (mem0ai).

Mem0's OSS package is a library, so this sidecar exposes the small contract Laravel's
Mem0MemoryProvider uses. Laravel stays the orchestrator and system of record; this
service only stores/searches embeddings in pgvector via Mem0.

Contract (all except /health require "Authorization: Bearer $MEM0_API_KEY"):
  GET    /health
  POST   /v1/memories                {scope, text, metadata}              -> {id}
  POST   /v1/memories/search         {scope, query, limit, threshold}     -> {results: [...]}
  GET    /v1/memories?scope=&limit=                                       -> {results: [...]}
  PUT    /v1/memories/{id}           {scope, text, metadata}              -> {ok}
  DELETE /v1/memories/{id}?scope=                                         -> {deleted}
  DELETE /v1/scopes/{scope}/memories                                      -> {deleted: n}

The Mem0 user_id is the opaque `scope` ("<prefix>:user:<id>") built by Laravel. Every
operation is filtered by scope and by-id operations verify ownership.
"""
from __future__ import annotations

import hashlib
import hmac
import logging
import math
import os
import re
import threading
from typing import Any, Optional

os.environ.setdefault("MEM0_TELEMETRY", "False")  # never phone home with usage data

from fastapi import Depends, FastAPI, Header, HTTPException, Query  # noqa: E402
from pydantic import BaseModel, Field  # noqa: E402
from mem0 import Memory  # noqa: E402

logging.basicConfig(level=os.environ.get("LOG_LEVEL", "INFO"), format='{"level":"%(levelname)s","logger":"%(name)s","msg":"%(message)s"}')
log = logging.getLogger("mem0-sidecar")

SCOPE_RE = re.compile(r"^[a-z0-9_-]{1,40}:user:\d{1,18}$")
API_KEY = os.environ.get("MEM0_API_KEY", "")
if len(API_KEY) < 24:
    raise SystemExit("MEM0_API_KEY must be set (>= 24 chars)")


def _env(name: str, default: Optional[str] = None, required: bool = False) -> str:
    value = os.environ.get(name, default)
    if required and not value:
        raise SystemExit(f"{name} is required")
    return value or ""


def build_config() -> dict[str, Any]:
    dims = int(_env("MEM0_EMBEDDING_DIMS", "1536"))
    config = {
        "vector_store": {
            "provider": "pgvector",
            "config": {
                "host": _env("MEM0_PG_HOST", "postgres"),
                "port": int(_env("MEM0_PG_PORT", "5432")),
                "dbname": _env("MEM0_PG_DB", "mem0"),
                "user": _env("MEM0_PG_USER", "mem0"),
                "password": _env("MEM0_PG_PASSWORD", required=True),
                "collection_name": _env("MEM0_COLLECTION", "companion_memories"),
                "embedding_model_dims": dims,
                "hnsw": True,
                "sslmode": _env("MEM0_PG_SSLMODE", "disable"),
            },
        },
        "embedder": {
            "provider": "openai",
            "config": {"model": _env("MEM0_EMBEDDING_MODEL", "text-embedding-3-small"), "embedding_dims": dims, "api_key": _env("OPENAI_API_KEY", required=True)},
        },
        # Required by Mem0's config, but unused: Laravel always calls add(infer=False).
        "llm": {"provider": "openai", "config": {"model": _env("MEM0_LLM_MODEL", required=True), "api_key": _env("OPENAI_API_KEY", required=True)}},
        "history_db_path": _env("MEM0_HISTORY_DB_PATH", "/data/history.db"),
    }
    # TEST ONLY: Mem0's local Qdrant store, for contract-testing the SDK where pgvector is unavailable.
    if os.environ.get("MEM0_VECTOR_PROVIDER") == "qdrant-local":
        if os.environ.get("MEM0_TEST_EMBEDDER") != "1":
            raise SystemExit("MEM0_VECTOR_PROVIDER=qdrant-local is only allowed together with MEM0_TEST_EMBEDDER=1")
        config["vector_store"] = {"provider": "qdrant", "config": {
            "collection_name": _env("MEM0_COLLECTION", "companion_memories"), "embedding_model_dims": dims,
            "path": _env("MEM0_QDRANT_PATH", "/tmp/qdrant-test"), "on_disk": False}}
    return config


class _DeterministicTestEmbedder:
    """TEST ONLY (MEM0_TEST_EMBEDDER=1): hashed bag-of-words vectors so the real SDK + pgvector
    path can be contract-tested without OpenAI credentials. Never enable in production."""

    def __init__(self, dims: int):
        self.dims = dims

    def embed(self, text: str, memory_action: Optional[str] = None) -> list[float]:
        vec = [0.0] * self.dims
        for word in re.findall(r"[a-z0-9]{3,}", text.lower()):
            vec[int(hashlib.sha256(word.encode()).hexdigest(), 16) % self.dims] += 1.0
        norm = math.sqrt(sum(v * v for v in vec)) or 1.0
        return [v / norm for v in vec]


TEST_MODE = os.environ.get("MEM0_TEST_EMBEDDER") == "1"
if TEST_MODE:
    # Compose passes unset vars as empty strings, so treat "" as unset (setdefault would not).
    for _name, _value in (("OPENAI_API_KEY", "sk-test-not-used"), ("MEM0_LLM_MODEL", "test-not-used")):
        if not os.environ.get(_name):
            os.environ[_name] = _value
    log.warning("MEM0_TEST_EMBEDDER=1: using deterministic test embeddings (NOT for production)")

memory = Memory.from_config(build_config())
if TEST_MODE:
    memory.embedding_model = _DeterministicTestEmbedder(int(_env("MEM0_EMBEDDING_DIMS", "1536")))
history_lock = threading.Lock()

app = FastAPI(title="companion-mem0-sidecar", docs_url=None, redoc_url=None, openapi_url=None)


def require_auth(authorization: str = Header(default="")) -> None:
    token = authorization.removeprefix("Bearer ").strip()
    if not hmac.compare_digest(token.encode(), API_KEY.encode()):
        raise HTTPException(status_code=401, detail="unauthorized")


def check_scope(scope: str) -> str:
    if not SCOPE_RE.match(scope or ""):
        raise HTTPException(status_code=422, detail="invalid scope")
    return scope


Scalar = Optional[str | int | float | bool]


class AddBody(BaseModel):
    scope: str
    text: str = Field(min_length=1, max_length=4000)
    metadata: dict[str, Scalar] = Field(default_factory=dict)


class SearchBody(BaseModel):
    scope: str
    query: str = Field(min_length=1, max_length=4000)
    limit: int = Field(default=10, ge=1, le=50)
    threshold: float = Field(default=0.1, ge=0.0, le=1.0)


class UpdateBody(BaseModel):
    scope: str
    text: str = Field(min_length=1, max_length=4000)
    metadata: dict[str, Scalar] = Field(default_factory=dict)


def _row(item: dict[str, Any], scope: str) -> dict[str, Any]:
    return {
        "id": item.get("id"),
        "memory": item.get("memory"),
        "score": item.get("score"),
        "metadata": item.get("metadata") or {},
        "created_at": item.get("created_at"),
        "updated_at": item.get("updated_at"),
        "scope": item.get("user_id", scope),
    }


def _owned(memory_id: str, scope: str) -> Optional[dict[str, Any]]:
    item = memory.get(memory_id)
    if item is None:
        return None
    if item.get("user_id") != scope:
        # Treat foreign ids as non-existent: never reveal or touch other scopes.
        return None
    return item


def _purge_history(memory_ids: list[str]) -> None:
    """Mem0 keeps old text in its SQLite history (even on DELETE). Purge it so deletes are real."""
    if not memory_ids:
        return
    with history_lock:
        conn = memory.db.connection
        conn.executemany("DELETE FROM history WHERE memory_id = ?", [(i,) for i in memory_ids])
        conn.commit()


@app.get("/health")
def health() -> dict[str, Any]:
    try:
        memory.vector_store.list_cols()
        return {"status": "ok", "test_mode": TEST_MODE}
    except Exception as exc:  # pragma: no cover
        log.error("health check failed: %s", type(exc).__name__)
        raise HTTPException(status_code=503, detail="vector store unavailable")


@app.post("/v1/memories", dependencies=[Depends(require_auth)])
def add(body: AddBody) -> dict[str, Any]:
    scope = check_scope(body.scope)
    # infer=False: Laravel already decided this is a durable memory; store it verbatim (1:1 id mapping).
    result = memory.add([{"role": "user", "content": body.text}], user_id=scope, metadata=dict(body.metadata), infer=False)
    items = result.get("results", []) if isinstance(result, dict) else result
    if not items:
        raise HTTPException(status_code=500, detail="mem0 returned no memory")
    log.info("add scope_hash=%s", hashlib.sha256(scope.encode()).hexdigest()[:10])
    return {"id": items[0]["id"]}


@app.post("/v1/memories/search", dependencies=[Depends(require_auth)])
def search(body: SearchBody) -> dict[str, Any]:
    scope = check_scope(body.scope)
    result = memory.search(body.query, filters={"user_id": scope}, top_k=body.limit, threshold=body.threshold)
    return {"results": [_row(i, scope) for i in result.get("results", []) if i.get("user_id", scope) == scope]}


@app.get("/v1/memories", dependencies=[Depends(require_auth)])
def list_memories(scope: str = Query(...), limit: int = Query(100, ge=1, le=1000)) -> dict[str, Any]:
    scope = check_scope(scope)
    result = memory.get_all(filters={"user_id": scope}, top_k=limit)
    return {"results": [_row(i, scope) for i in result.get("results", []) if i.get("user_id", scope) == scope]}


@app.put("/v1/memories/{memory_id}", dependencies=[Depends(require_auth)])
def update(memory_id: str, body: UpdateBody) -> dict[str, Any]:
    scope = check_scope(body.scope)
    if _owned(memory_id, scope) is None:
        raise HTTPException(status_code=404, detail="not found")
    memory.update(memory_id, text=body.text, metadata=dict(body.metadata))
    _purge_history([memory_id])  # don't retain the pre-update text either
    return {"ok": True}


@app.delete("/v1/memories/{memory_id}", dependencies=[Depends(require_auth)])
def delete(memory_id: str, scope: str = Query(...)) -> dict[str, Any]:
    scope = check_scope(scope)
    if _owned(memory_id, scope) is None:
        raise HTTPException(status_code=404, detail="not found")
    memory.delete(memory_id)
    _purge_history([memory_id])
    return {"deleted": True}


@app.delete("/v1/scopes/{scope}/memories", dependencies=[Depends(require_auth)])
def delete_scope(scope: str) -> dict[str, Any]:
    scope = check_scope(scope)
    ids: list[str] = []
    while True:
        batch = memory.get_all(filters={"user_id": scope}, top_k=500).get("results", [])
        if not batch:
            break
        for item in batch:
            memory.delete(item["id"])
            ids.append(item["id"])
    _purge_history(ids)
    return {"deleted": len(ids)}
