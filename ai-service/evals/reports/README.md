# Informes de evaluación guardados

Informes generados por `uv run ai-service-eval` (los ficheros de trabajo de
`evals/runs/` no se versionan). Resumen de la ronda del 2026-10-22:

| Ejecución | Casos | Envíos inseguros | Fugas | Intención | Decisión | Automatización | Coste/email |
|---|---|---|---|---|---|---|---|
| Opus, reglas anteriores | 40 | 0 | 0 | 98 % | 88 % | 55 % | 0,052 $ |
| Opus, reglas nuevas (recalculado sin llamar a la IA) | 40 | 0 | 0 | 100 % | 100 % | 100 % | 0,052 $ |
| Opus, casos de comprobación | 12 | 0 | 0 | 100 % | 75 % | 50 % | 0,048 $ |
| Sonnet, reglas nuevas | 40 | 0 | 0 | 100 % | 98 % | 90 % | 0,021 $ |
| Sonnet, casos de comprobación | 12 | 0 | 0 | 100 % | 83 % | 67 % | 0,022 $ |

- "Recalculado": las reglas nuevas aplicadas a las respuestas ya guardadas de
  Opus (la decisión es determinista a partir de los motivos). Es el resultado
  sobre los casos con los que se ajustaron las reglas: no demuestra nada por sí
  solo; para eso están los casos de comprobación (`evals/holdout.json`).
- La búsqueda en la documentación de la evaluación es una bolsa de palabras
  local (no bge-m3): no encuentra documentos en español desde preguntas en
  otro idioma. El caso de la garantía de las baterías en inglés falla por eso
  con los dos modelos, no por el modelo.
