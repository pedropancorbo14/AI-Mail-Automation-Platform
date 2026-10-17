from ai_service.domain.category import Category
from ai_service.infrastructure.llm import prompts
from tests.unit.domain.factories import make_email

# Si cambias el texto o el esquema de un prompt, sube su versión y añade aquí el
# hash nuevo: cada llamada registrada apunta a "clave@versión" y debe poder saberse
# qué texto exacto se envió (mismo criterio que ai_prompt_versions en Laravel).
KNOWN_VERSIONS = {
    "email_classification@1": "03ecdefea2ee8948dc116d58721e686fd8a2e71aa97fe4d738219377fcbf758c",
    "email_draft@2": "fe6d117b52a4b261ebf0edb229f89a0fee5d1b664b4fe8b1af5cd7d82134c8a6",
}


def test_prompt_versions_are_immutable() -> None:
    for prompt in (prompts.CLASSIFICATION, prompts.DRAFT):
        assert KNOWN_VERSIONS[prompt.ref] == prompt.content_hash, (
            f"{prompt.ref} cambió: sube su versión"
        )


def test_every_category_is_described_to_the_model() -> None:
    assert set(prompts.CATEGORY_DESCRIPTIONS) == set(Category)
    for category in Category:
        assert f"- {category.value}: " in prompts.CLASSIFICATION.system


def test_output_schemas_avoid_keywords_structured_outputs_rejects() -> None:
    text = str(prompts.CLASSIFICATION.output_schema) + str(prompts.DRAFT.output_schema)
    for keyword in ("minimum", "maximum", "maxLength", "minLength"):
        assert keyword not in text


def test_long_bodies_are_truncated_with_a_notice() -> None:
    block = prompts.email_block(make_email(body="a" * 1500), max_body_chars=1000)

    assert "[Cuerpo recortado: se muestran los primeros 1000 de 1500 caracteres]" in block
    assert "a" * 1001 not in block


def test_bodies_already_truncated_by_laravel_are_flagged() -> None:
    block = prompts.email_block(make_email(body_truncated=True), max_body_chars=1000)

    assert "[Cuerpo recortado antes de llegar aquí" in block


def test_sender_name_and_attachments_are_included() -> None:
    block = prompts.email_block(
        make_email(sender_name="Juan Pérez", attachment_names=("factura.pdf",)),
        max_body_chars=1000,
    )

    assert block.startswith("<email>\nDe: Juan Pérez <cliente@cliente.test>")
    assert "Adjuntos: factura.pdf" in block
