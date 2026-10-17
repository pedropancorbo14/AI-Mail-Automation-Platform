"""Regla de decisión: qué recomendar con un email ya clasificado y su borrador.

Reglas puras y deterministas (sin IA): se pueden explicar, probar y auditar.
Se devuelven TODOS los motivos, no solo el primero, para que la persona que
revise vea el cuadro completo en la bandeja del panel.
"""

import re
from dataclasses import dataclass, field

from ai_service.domain.category import Category
from ai_service.domain.classification import Classification, Urgency
from ai_service.domain.commitments import forbidden_commitments
from ai_service.domain.confidence import Confidence
from ai_service.domain.decision import Decision, DecisionOutcome
from ai_service.domain.draft import Draft
from ai_service.domain.email import Email

DEFAULT_MIN_CONFIDENCE = Confidence(0.85)


@dataclass(frozen=True, slots=True)
class Evidence:
    """Lo que la IA consultó de verdad para preparar la respuesta.

    - `references`: fuentes que se le dieron (documentos, pedidos...). Una
      respuesta que cita algo que no está aquí se lo ha inventado.
    - `issues`: problemas al consultar (documentación no disponible, pedido no
      encontrado...). Cualquiera manda el caso a una persona.
    """

    references: frozenset[str] = frozenset()
    issues: tuple[str, ...] = ()


# Amenazas legales: siempre escalado (una persona con prioridad).
_LEGAL_THREAT = re.compile(
    r"\b(abogad[oa]s?|denunciar(é|emos)?|poner\s+una\s+(denuncia|demanda)|demandar(é|emos)?|"
    r"tribunal(es)?|juzgado|vía\s+judicial|OCU|lawyers?|attorney|lawsuit|legal\s+action|"
    r"take\s+you\s+to\s+court|sue\s+you)\b",
    re.IGNORECASE,
)


@dataclass(frozen=True, slots=True)
class DecisionPolicy:
    min_confidence: Confidence = field(default=DEFAULT_MIN_CONFIDENCE)

    def decide(
        self,
        email: Email,
        classification: Classification,
        draft: Draft | None,
        evidence: Evidence | None = None,
    ) -> DecisionOutcome:
        evidence = evidence or Evidence()
        escalate: list[str] = []
        review: list[str] = list(evidence.issues)

        if classification.injection_suspected:
            escalate.append("Posible intento de manipular a la IA: no se propone respuesta.")
        if self._legal_threat(email, classification):
            escalate.append("El cliente menciona acciones legales.")

        if not classification.category.needs_reply:
            review.append(f"No necesita respuesta ({classification.category.value}).")
        if classification.confidence.is_below(self.min_confidence):
            review.append(
                f"La clasificación no es segura (confianza {classification.confidence}, "
                f"mínimo {self.min_confidence})."
            )
        if classification.needs_human and not classification.injection_suspected:
            review.append(
                classification.needs_human_reason or "La clasificación pide revisión humana."
            )
        if email.body_truncated:
            review.append("El email era demasiado largo y la IA solo leyó una parte.")
        if email.review_instruction:
            # Quien la dio quiere ver cómo quedó antes de que salga.
            review.append("Redactada con una indicación del equipo: la revisa una persona.")

        if draft is None or draft.is_empty:
            if classification.category.needs_reply and not escalate:
                review.append("La IA no preparó una respuesta.")
        else:
            if draft.confidence.is_below(self.min_confidence):
                review.append(
                    f"La confianza en la respuesta ({draft.confidence}) es menor que el "
                    f"mínimo ({self.min_confidence})."
                )
            if draft.needs_human:
                review.append(draft.needs_human_reason or "La IA pide que la revise una persona.")
            if draft.pending_actions:
                review.append(
                    "Requiere acciones que solo puede hacer una persona: "
                    + "; ".join(draft.pending_actions)
                    + "."
                )
            review.extend(forbidden_commitments(draft.body))
            known = {r.casefold() for r in evidence.references}
            for source in draft.sources:
                if source.reference.casefold() not in known:
                    review.append(f"La respuesta cita algo que no consultó: {source.reference}.")

        if escalate:
            return DecisionOutcome(Decision.ESCALATE, tuple(escalate + review))
        if review:
            return DecisionOutcome(Decision.HUMAN_REVIEW, tuple(review))
        return DecisionOutcome(Decision.AUTO_SEND)

    @staticmethod
    def _legal_threat(email: Email, classification: Classification) -> bool:
        if classification.category is Category.COMPLAINT and classification.urgency is Urgency.HIGH:
            return True
        return bool(_LEGAL_THREAT.search(f"{email.subject}\n{email.body}"))
