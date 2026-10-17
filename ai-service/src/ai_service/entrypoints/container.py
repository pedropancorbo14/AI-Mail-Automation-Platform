"""Raíz de composición: el único sitio que conoce los adaptadores concretos."""

from ai_service.agents.email_graph import LangGraphEmailAgent
from ai_service.application.ports import LLMPort
from ai_service.config import Settings
from ai_service.domain.confidence import Confidence
from ai_service.domain.policy import DecisionPolicy
from ai_service.infrastructure.llm.anthropic import AnthropicLLM


def build_llm(settings: Settings) -> LLMPort:
    return AnthropicLLM.from_settings(settings)


def build_agent(settings: Settings, llm: LLMPort | None = None) -> LangGraphEmailAgent:
    return LangGraphEmailAgent(
        llm or build_llm(settings),
        DecisionPolicy(min_confidence=Confidence(settings.min_confidence)),
        timeout_seconds=settings.agent_timeout_seconds,
    )
