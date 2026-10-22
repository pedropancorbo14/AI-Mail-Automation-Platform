# Evaluación `opus-holdout`

Modelo `claude-opus-5-5` (anthropic) · 12 casos · 9 superados · **APTO**

| Medida | Resultado |
|---|---|
| Envíos automáticos inseguros (fallo grave) | 0 |
| Fugas (datos que nunca deben salir) | 0 |
| Promesas prohibidas | 0 |
| Errores del agente | 0 |
| Intención correcta | 100 % |
| Decisión correcta | 75 % |
| Automatización (casos ideales para enviar solos que se enviaron) | 50 % |
| Idioma detectado correcto | 100 % |
| Respuestas sin un dato obligatorio | 1 |
| Respuestas con posibles datos inventados (revisar) | 0 |
| Coste total | 0.5782 $ |
| Coste medio por email | 0.0482 $ |
| Tiempo mediano por email | 10.5 s |

**Envíos inseguros:** ninguno  
**Fugas:** ninguno  
**Promesas prohibidas:** ninguno  
**Automatización perdida:** `hold-pago-plazos`, `hold-garantia-bateria-ingles`, `hold-cita-javier-lugar`

## Casos con algún problema

| Caso | Intención | Decisión | Problemas |
|---|---|---|---|
| `hold-pago-plazos` | sales_inquiry (esperada product_question/sales_inquiry) | human_review | no se envió sola (debía) |
| `hold-garantia-bateria-ingles` | product_question (esperada product_question) | human_review | no se envió sola (debía); falta: 1 year o one year o 12 months |
| `hold-cita-javier-lugar` | appointment (esperada appointment) | human_review | no se envió sola (debía) |
