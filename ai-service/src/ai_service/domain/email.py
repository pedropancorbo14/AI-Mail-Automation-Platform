"""El email a procesar y sus value objects."""

from dataclasses import dataclass, field
from datetime import datetime

from ai_service.domain.errors import DomainError


@dataclass(frozen=True, slots=True)
class TenantId:
    """Empresa. Todo lo que se consulta para un email se acota a su empresa."""

    value: int

    def __post_init__(self) -> None:
        if self.value < 1:
            raise DomainError("El id de empresa debe ser positivo.")

    def __str__(self) -> str:
        return str(self.value)


@dataclass(frozen=True, slots=True)
class EmailAddress:
    """Dirección normalizada (sin espacios, en minúsculas) para compararla."""

    value: str

    def __post_init__(self) -> None:
        normalized = self.value.strip().lower()
        local, at, domain = normalized.partition("@")
        if (
            not at
            or not local
            or not domain
            or "@" in domain
            or any(c.isspace() for c in normalized)
        ):
            raise DomainError("Dirección de email no válida.")
        object.__setattr__(self, "value", normalized)

    @property
    def domain(self) -> str:
        return self.value.partition("@")[2]

    def __str__(self) -> str:
        return self.value


@dataclass(frozen=True, slots=True)
class Email:
    """Email recibido por una empresa. Su contenido es un DATO NO FIABLE (puede
    intentar manipular a la IA): nunca se trata como instrucciones."""

    email_id: int
    tenant_id: TenantId
    sender: EmailAddress
    subject: str
    body: str
    received_at: datetime
    sender_name: str | None = None
    body_truncated: bool = False
    attachment_names: tuple[str, ...] = field(default_factory=tuple)
    tenant_name: str | None = None
    # Indicación de una persona del equipo al reprocesar desde el panel. Es de la
    # empresa (no del cliente), pero no cambia las reglas de la decisión.
    review_instruction: str | None = None

    def __post_init__(self) -> None:
        if self.email_id < 1:
            raise DomainError("El id del email debe ser positivo.")
        if self.received_at.tzinfo is None:
            raise DomainError("La fecha de recepción debe llevar zona horaria.")
