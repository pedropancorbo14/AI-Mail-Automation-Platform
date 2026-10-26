# Informes de evaluación guardados

Informes generados por `uv run ai-service-eval` (los ficheros de trabajo de
`evals/runs/` no se versionan). **Comparación completa de los dos modelos** (seguridad, calidad, confianza, coste,
tokens, tiempos, herramientas, por intención y caso a caso):
[`2026-10-22-opus-vs-sonnet.md`](2026-10-22-opus-vs-sonnet.md).

Resumen de la ronda del 2026-10-22:

| Ejecución | Casos | Envíos inseguros | Fugas | Intención | Decisión | Automatización | Coste/email |
|---|---|---|---|---|---|---|---|
| Opus, reglas anteriores | 40 | 0 | 0 | 98 % | 88 % | 55 % | 0,052 $ |
| Opus, reglas nuevas (recalculado sin llamar a la IA) | 40 | 0 | 0 | 100 % | 100 % | 100 % | 0,052 $ |
| Opus, casos de comprobación | 12 | 0 | 0 | 100 % | 75 % | 50 % | 0,048 $ |
| Sonnet, reglas nuevas | 40 | 0 | 0 | 100 % | 98 % | 90 % | 0,021 $ |
| Sonnet, casos de comprobación | 12 | 0 | 0 | 100 % | 83 % | 67 % | 0,022 $ |

Ronda del 2026-10-26 (Haiku 5.5 clasifica, Sonnet 5.5 redacta; misma búsqueda
local; informes `2026-10-26-haiku-sonnet*.md`):

| Ejecución | Casos | Envíos inseguros | Fugas | Intención | Decisión | Automatización | Coste/email |
|---|---|---|---|---|---|---|---|
| Haiku + Sonnet | 40 | 0 | 0 | 100 % | 98 % | 90 % | 0,015 $ |
| Haiku + Sonnet, casos de comprobación | 12 | 0 | 0 | 100 % | 83 % | 67 % | 0,016 $ |

- Mismas cifras que solo Sonnet y ~28 % más barato: **Haiku pasa a ser el
  modelo de clasificación por defecto**. Los casos que no se envían solos
  fallan al redactar, no al clasificar: en `envio-lisboa-ingles` (que con
  solo Sonnet sí salió) y `hold-envio-mallorca` el modelo siguió pidiendo
  consultas tras 2 rondas; la garantía de las baterías en inglés sigue
  fallando por la búsqueda local. Comparado contra los informes del
  2026-10-22 (los ficheros de trabajo de Sonnet no estaban en este equipo);
  coste de la ronda: 0,82 $.

Con la **búsqueda real (bge-m3 en Ollama, umbral 0,45)**, mismos modelos
(`--embeddings ollama`; informes `2026-10-26-haiku-sonnet-bge*.md` y
comparaciones `2026-10-26-compare-local-vs-bge*.md`):

| Ejecución | Casos | Superados | Envíos inseguros | Fugas | Intención | Decisión | Automatización | Coste/email |
|---|---|---|---|---|---|---|---|---|
| Haiku + Sonnet, bge-m3 | 40 | 40 | 0 | 0 | 100 % | 100 % | 100 % | 0,015 $ |
| Haiku + Sonnet, bge-m3, casos de comprobación | 12 | 12 | 0 | 0 | 100 % | 100 % | 100 % | 0,015 $ |

- Mejoran `envio-lisboa-ingles`, `hold-envio-mallorca` y
  `hold-garantia-bateria-ingles`: lo que fallaba era la búsqueda local
  (preguntas en inglés sobre documentos en español), no la IA. Ningún fallo
  de seguridad nuevo. `queja-servicio` pasa de revisión humana a escalar
  (las dos son correctas). Coste de la ronda: 0,78 $.
- `hold-garantia-bateria-ingles`: la expectativa no aceptaba "1-year" (la
  respuesta decía "a 1-year warranty"); se añadió la forma con guion con su
  `note`. Solo cambia cómo se reconoce el dato.

- "Recalculado": las reglas nuevas aplicadas a las respuestas ya guardadas de
  Opus (la decisión es determinista a partir de los motivos). Es el resultado
  sobre los casos con los que se ajustaron las reglas: no demuestra nada por sí
  solo; para eso están los casos de comprobación (`evals/holdout.json`).
- La búsqueda por defecto de la evaluación es una bolsa de palabras local
  (no bge-m3): no encuentra documentos en español desde preguntas en otro
  idioma. Para medir la IA como en producción, `--embeddings ollama`.
