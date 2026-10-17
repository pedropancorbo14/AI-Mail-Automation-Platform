import pytest
from pydantic import ValidationError

from ai_service.config import Environment, Settings


def test_defaults_are_safe_for_local_development() -> None:
    settings = Settings(_env_file=None)

    assert settings.environment is Environment.LOCAL
    assert settings.log_level == "INFO"
    assert settings.http_port == 8000
    assert not settings.is_production


def test_reads_prefixed_environment_variables(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("AI_SERVICE_ENVIRONMENT", "production")
    monkeypatch.setenv("AI_SERVICE_LOG_LEVEL", "WARNING")
    monkeypatch.setenv("AI_SERVICE_HTTP_PORT", "9100")

    settings = Settings(_env_file=None)

    assert settings.is_production
    assert settings.log_level == "WARNING"
    assert settings.http_port == 9100


@pytest.mark.parametrize(
    ("variable", "value"),
    [
        ("AI_SERVICE_ENVIRONMENT", "staging"),
        ("AI_SERVICE_LOG_LEVEL", "VERBOSE"),
        ("AI_SERVICE_HTTP_PORT", "70000"),
    ],
)
def test_rejects_invalid_values(monkeypatch: pytest.MonkeyPatch, variable: str, value: str) -> None:
    monkeypatch.setenv(variable, value)

    with pytest.raises(ValidationError):
        Settings(_env_file=None)


def test_settings_are_immutable() -> None:
    settings = Settings(_env_file=None)

    with pytest.raises(ValidationError):
        settings.http_port = 1  # type: ignore[misc]
