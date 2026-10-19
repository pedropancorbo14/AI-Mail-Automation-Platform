# API estándar de datos de clientes (v1)

Para empresas que **no** usan una tienda con conector propio (WooCommerce,
PrestaShop, Shopify…) sino un ERP o un programa propio. La plataforma
define esta API; el informático de la empresa la implementa **una vez** en
su sistema y la plataforma la consulta cuando la IA necesita los pedidos o
facturas del cliente que ha escrito un email.

La plataforma también la implementa ella misma para los pedidos importados
por CSV: así el servidor MCP tiene un solo proveedor genérico para las dos
cosas.

- Especificación: [`standard-api.v1.openapi.yaml`](standard-api.v1.openapi.yaml)
- Esquemas (JSON Schema 2020-12): [`schemas/`](schemas/)
- Ejemplos válidos e inválidos (`<esquema>--<caso>.json`): [`examples/`](examples/),
  validados en los tests de ai-service y de Laravel.

## Endpoints (solo lectura)

| Método y ruta | Devuelve |
|---|---|
| `GET /v1/orders/{number}?customer_email=` | Un pedido (`order.v1`) o 404 |
| `GET /v1/orders?customer_email=&limit=` | `{"data": [pedidos]}`, del más reciente al más antiguo |
| `GET /v1/invoices/{number}?customer_email=` | Una factura (`invoice.v1`) o 404 |
| `GET /v1/invoices?customer_email=&limit=` | `{"data": [facturas]}`, de la más reciente a la más antigua |

Las rutas son relativas a la **URL base** que la empresa configura en la
plataforma (siempre HTTPS), p. ej. `https://erp.ejemplo.com/aimail`. Una
empresa puede implementar solo los pedidos (indica en la plataforma qué
partes tiene).

## Reglas

1. **Autenticación:** `Authorization: Bearer <clave>`. La clave la genera la
   empresa y se guarda cifrada en la plataforma. Clave ausente o incorrecta →
   401 o 403.
2. **Solo datos del cliente indicado.** Filtra siempre por `customer_email`
   (el remitente del email, en minúsculas). Un número que existe pero es de
   **otro** cliente se responde exactamente igual que uno que no existe:
   404. La plataforma, además, vuelve a comprobar el email de cada dato
   recibido y descarta lo que no coincida: un fallo de filtrado no llega a la
   IA, pero no lo hagas depender de eso.
3. **Lo mínimo para responder.** No incluyas la dirección completa, teléfonos
   ni datos de pago: basta con ciudad y país. Los campos que la plataforma no
   conoce se ignoran (puedes añadir los tuyos sin romper nada).
4. **Listas:** como máximo `limit` elementos (1–100), del más reciente al más
   antiguo; vacía (`{"data": []}`) si no hay nada, **nunca 404**.
5. **Estados** (fijos, para que la IA los entienda igual en todas las
   empresas): pedidos `pending_payment`, `processing`, `shipped`,
   `delivered`, `cancelled`, `returned`; facturas `paid`, `refunded`,
   `pending`. Traduce los tuyos a estos.
6. **Fechas** ISO 8601 con zona horaria; importes con punto decimal; moneda
   ISO 4217 (`EUR`); país ISO 3166-1 alfa-2 (`ES`).
7. **Rendimiento:** responde en menos de 5 s y con menos de 1 MB. Sin
   redirecciones (la plataforma no las sigue).

## Qué hace la plataforma con cada respuesta

| Respuesta | Resultado |
|---|---|
| 200 que cumple el esquema | Los datos llegan a la IA (solo los del cliente) |
| 404 en un pedido o factura concretos | "No hay ningún pedido con ese número asociado a este cliente" |
| 401 / 403 | Error "rechazó las credenciales": el email va a revisión humana |
| 429, 5xx, tiempo agotado, sin conexión | Error "no responde": revisión humana |
| JSON no válido, no cumple el esquema, > 1 MB, redirección, 404 en una lista | Error "respuesta no válida": revisión humana |

Un error nunca se convierte en "no encontrado": la IA no le diría al
cliente algo falso.

## Versionado

Añadir campos opcionales no rompe la v1. Quitar o renombrar campos, o
cambiar su significado, exige `/v2/`.
