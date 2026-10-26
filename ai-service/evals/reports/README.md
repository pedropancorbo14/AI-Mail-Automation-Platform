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

- "Recalculado": las reglas nuevas aplicadas a las respuestas ya guardadas de
  Opus (la decisión es determinista a partir de los motivos). Es el resultado
  sobre los casos con los que se ajustaron las reglas: no demuestra nada por sí
  solo; para eso están los casos de comprobación (`evals/holdout.json`).
- La búsqueda en la documentación de la evaluación es una bolsa de palabras
  local (no bge-m3): no encuentra documentos en español desde preguntas en
  otro idioma. El caso de la garantía de las baterías en inglés falla por eso
  con los dos modelos, no por el modelo.
