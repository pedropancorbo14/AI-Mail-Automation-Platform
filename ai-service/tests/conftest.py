import os

import pytest


@pytest.fixture(autouse=True)
def _isolated_settings_env(monkeypatch: pytest.MonkeyPatch) -> None:
    """Los tests no dependen de las variables AI_SERVICE_* de quien los lanza (CI,
    terminal). Se conservan las AI_SERVICE_TEST_*, que apuntan a servicios de test."""
    for name in list(os.environ):
        if name.startswith("AI_SERVICE_") and not name.startswith(
            ("AI_SERVICE_TEST_", "AI_SERVICE_CONTRACTS_DIR")
        ):
            monkeypatch.delenv(name)
