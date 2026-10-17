"""Raíz de composición: el único sitio que conoce los adaptadores concretos."""

from ai_service.agents.email_graph import LangGraphEmailAgent
from ai_service.application.errors import LLMConfigurationError
from ai_service.application.ports import LLMPort
from ai_service.application.services.knowledge import KnowledgeRetriever, RetrievalOptions
from ai_service.config import Environment, Settings
from ai_service.domain.confidence import Confidence
from ai_service.domain.policy import DecisionPolicy
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


def build_retriever(settings: Settings) -> KnowledgeRetriever | None:
    if settings.database_url is None:
        return None
    return KnowledgeRetriever(
        OllamaEmbeddings.create(
            settings.ollama_url,
            model=settings.embedding_model,
            dimensions=settings.embedding_dimensions,
            timeout=settings.embedding_timeout_seconds,
        ),
        PgVectorStore.create(settings.database_url.get_secret_value()),
        RetrievalOptions(limit=settings.rag_limit, min_similarity=settings.rag_min_similarity),
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


def build_agent(settings: Settings, llm: LLMPort | None = None) -> LangGraphEmailAgent:
    return LangGraphEmailAgent(
        llm or build_llm(settings),
        DecisionPolicy(min_confidence=Confidence(settings.min_confidence)),
        retriever=build_retriever(settings),
        tools=build_tools(settings),
        max_tool_calls=settings.max_tool_calls,
        max_tokens=settings.max_tokens_per_email,
        max_steps=settings.max_graph_steps,
        timeout_seconds=settings.agent_timeout_seconds,
    )
