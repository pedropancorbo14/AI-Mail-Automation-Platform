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


def build_agent(settings: Settings, llm: LLMPort | None = None) -> LangGraphEmailAgent:
    return LangGraphEmailAgent(
        llm or build_llm(settings),
        DecisionPolicy(min_confidence=Confidence(settings.min_confidence)),
        retriever=build_retriever(settings),
        timeout_seconds=settings.agent_timeout_seconds,
    )
