"""Raíz de composición: el único sitio que conoce los adaptadores concretos."""

from typing import Any

from langgraph.checkpoint.base import BaseCheckpointSaver

from ai_service.agents.email_graph import LangGraphEmailAgent
from ai_service.application.errors import LLMConfigurationError
from ai_service.application.ports import EventBusPort, LLMPort
from ai_service.application.services.chunking import TextChunker
from ai_service.application.services.knowledge import KnowledgeRetriever, RetrievalOptions
from ai_service.application.use_cases.index_document import IndexDocument
from ai_service.config import Environment, Settings
from ai_service.domain.confidence import Confidence
from ai_service.domain.policy import DecisionPolicy
from ai_service.infrastructure.documents.download import LaravelDocumentSource
from ai_service.infrastructure.documents.extraction import DocumentTextExtractor
from ai_service.infrastructure.embeddings.ollama import OllamaEmbeddings
from ai_service.infrastructure.knowledge.pgvector import PgVectorStore
from ai_service.infrastructure.llm.anthropic import AnthropicLLM
from ai_service.infrastructure.llm.simulated import SimulatedLLM
from ai_service.infrastructure.tools.mcp_client import McpToolClient


def build_llm(settings: Settings) -> LLMPort:
    if settings.llm_provider == "simulated":
        if settings.environment not in (Environment.LOCAL, Environment.TESTING):
            raise LLMConfigurationError(
                "La IA simulada solo está permitida en local (AI_SERVICE_LLM_PROVIDER=simulated)."
            )
        return SimulatedLLM(settings.simulated_delay_ms)
    return AnthropicLLM.from_settings(settings)


def build_embeddings(settings: Settings) -> OllamaEmbeddings:
    return OllamaEmbeddings.create(
        settings.ollama_url,
        model=settings.embedding_model,
        dimensions=settings.embedding_dimensions,
        timeout=settings.embedding_timeout_seconds,
    )


def build_knowledge_store(settings: Settings) -> PgVectorStore | None:
    if settings.database_url is None:
        return None
    return PgVectorStore.create(
        settings.database_url.get_secret_value(), settings.embedding_dimensions
    )


def build_retriever(
    settings: Settings, store: PgVectorStore | None = None
) -> KnowledgeRetriever | None:
    store = store or build_knowledge_store(settings)
    if store is None:
        return None
    return KnowledgeRetriever(
        build_embeddings(settings),
        store,
        RetrievalOptions(limit=settings.rag_limit, min_similarity=settings.rag_min_similarity),
    )


def build_document_indexer(
    settings: Settings, store: PgVectorStore, bus: EventBusPort
) -> IndexDocument:
    return IndexDocument(
        LaravelDocumentSource(
            settings.laravel_url,
            timeout=settings.document_download_timeout_seconds,
            max_bytes=settings.document_max_mb * 1024 * 1024,
        ),
        DocumentTextExtractor(),
        TextChunker(settings.chunk_max_chars, settings.chunk_overlap_chars),
        build_embeddings(settings),
        store,
        bus,
        batch_size=settings.embedding_batch_size,
    )


def build_tools(settings: Settings) -> McpToolClient | None:
    if not settings.mcp_url:
        return None
    if settings.mcp_token is None or not settings.mcp_token.get_secret_value():
        raise LLMConfigurationError("Falta AI_SERVICE_MCP_TOKEN para usar el servidor MCP.")
    return McpToolClient(
        settings.mcp_url,
        settings.mcp_token.get_secret_value(),
        timeout_seconds=settings.mcp_timeout_seconds,
    )


def build_agent(
    settings: Settings,
    llm: LLMPort | None = None,
    checkpointer: BaseCheckpointSaver[Any] | None = None,
) -> LangGraphEmailAgent:
    """`checkpointer` (lo abre el worker): sin él, el agente no propone acciones
    que modifican datos (no podría esperar la aprobación)."""
    tools = build_tools(settings)
    return LangGraphEmailAgent(
        llm or build_llm(settings),
        DecisionPolicy(min_confidence=Confidence(settings.min_confidence)),
        retriever=build_retriever(settings),
        tools=tools,
        actions=tools,
        checkpointer=checkpointer,
        max_tool_calls=settings.max_tool_calls,
        max_model_rounds=settings.max_model_tool_rounds,
        max_model_lookups=settings.max_model_tool_calls,
        max_tokens=settings.max_tokens_per_email,
        max_steps=settings.max_graph_steps,
        timeout_seconds=settings.agent_timeout_seconds,
    )
