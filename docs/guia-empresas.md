# Guía para empresas

Esta guía es para las empresas que usan la plataforma: qué hace, cómo
empezar y qué esperar de la IA. No hace falta saber nada técnico.

## Qué hace la plataforma

Conectas el buzón de atención al cliente (por ejemplo, `soporte@tuempresa.com`).
Cada email que llega:

1. Se guarda en la plataforma; en tu buzón no se toca nada.
2. La IA lo lee, entiende qué pide el cliente (un pedido, una devolución, una
   factura…) y prepara una respuesta con **tu documentación** y, si los
   conectas, **tus datos** (pedidos, facturas, citas). Solo consulta los datos
   del cliente que escribe.
3. Según cómo lo configures, la respuesta **se envía sola** o **la revisa una
   persona** de tu equipo, que puede aprobarla tal cual, corregirla o escribir
   otra.

La IA nunca promete reembolsos, cancelaciones ni cambios: esos casos siempre
los decide una persona. Tampoco responde a correos automáticos (boletines,
avisos de "fuera de la oficina", spam).

## Primeros pasos

Al entrar por primera vez verás la lista **Primeros pasos** en el Resumen:

1. **Conecta el buzón** (sección *Buzones*). Con Microsoft 365 o Gmail, pulsa
   "Conectar" e inicia sesión con la cuenta. Con cualquier otro correo usa
   IMAP: escribe el email y la plataforma intenta detectar la configuración
   sola. Solo se importan los correos que lleguen a partir de ese momento.
2. **Sube tu documentación** (sección *Documentación*): políticas de
   devolución y envío, preguntas frecuentes, condiciones, garantías… en PDF,
   Word guardado como PDF, Markdown, HTML o texto. La IA responde **solo** con
   lo que digan estos documentos: si algo no está, pasa el email a una persona
   en vez de inventarlo. Con "Probar la búsqueda" ves qué encontraría.
3. **Revisa los ajustes de la IA** (sección *Ajustes de la IA*). Al empezar,
   todo va a revisión de tu equipo (modo **Borrador**). Cuando veas que las
   respuestas son buenas, cambia a **Automático** y elige qué tipos de email
   puede responder sola (por ejemplo, "estado del pedido" o "pregunta sobre
   un producto"). Las quejas, los problemas de cobro y el spam siempre los ve
   una persona.
4. **Invita a tu equipo** (sección *Empresa y usuarios*): los **agentes**
   revisan y responden; los **administradores** además configuran; el
   **propietario** además gestiona el plan y el pago.
5. **Escribe un email de prueba** al buzón y míralo en *Revisión*.

Si tienes un sistema de pedidos, una tienda WooCommerce o una agenda de citas,
conéctalo en *Integraciones* (o sube tus pedidos en un CSV en *Pedidos*) para
que la IA pueda responder "¿dónde está mi pedido?" con datos reales.

## La bandeja de revisión

En *Revisión* están los emails que esperan a una persona, los urgentes
primero. Para cada uno ves el email, lo que entendió la IA, la respuesta que
propone y qué datos consultó. Puedes:

- **Aprobar y enviar** la propuesta, tal cual o corregida. Si al corregirla
  pones un dato que no sale de ningún sitio (un número de pedido, un importe,
  un enlace), la plataforma te avisa antes de enviar.
- **Descartar** la propuesta y escribir tu respuesta.
- **Cerrar** el caso sin responder.
- **Reprocesar** con la IA, opcionalmente con una indicación ("ofrécele el
  cambio en vez de la devolución").
- **Asignártelo** para que tu equipo sepa quién lo lleva.

Las respuestas se envían desde tu buzón, en el mismo hilo del cliente.

## Plan y uso

En *Plan y uso* ves tu plan, el estado de la suscripción y cuánto llevas
usado este mes:

| Límite | Qué cuenta |
|---|---|
| Emails con IA al mes | Emails que la IA ha procesado este mes (reprocesar uno también cuenta). Los correos automáticos no cuentan. |
| Capacidad de IA del mes | Cuánto trabajo de IA llevas este mes, en porcentaje. Los emails largos o que necesitan muchas consultas usan más. |
| Buzones | Buzones conectados a la vez. Uno desactivado no cuenta. |
| Usuarios | Personas de tu equipo con acceso. |
| Documentos | Documentos subidos. |

**Si llegas a un límite de IA**, la plataforma **sigue recibiendo y
guardando todos los emails**: los nuevos los atiende tu equipo desde la
bandeja de revisión hasta el mes siguiente o hasta que cambies de plan.
Nunca se pierde un correo de un cliente. Cuando un límite pasa del 80 %
verás un aviso arriba en todas las páginas.

Si llegas al límite de buzones, usuarios o documentos, la plataforma te lo
dice al intentar añadir uno más.

### Periodo de prueba

Las cuentas nuevas empiezan con unos días de prueba gratis, sin tarjeta.
Cuando termina, la IA se para (los emails se siguen recibiendo) hasta que
elijas un plan.

### Contratar y cambiar de plan

Solo el **propietario** de la empresa puede hacerlo, desde *Plan y uso*:

- **Contratar**: te lleva a la página de pago segura de Stripe. La tarjeta
  nunca pasa por la plataforma. Al volver, el plan se activa en unos segundos.
- **Gestionar suscripción**: cambiar de plan, cambiar la tarjeta, descargar
  facturas o cancelar.

Si un cobro falla, tienes unos días para actualizar la tarjeta antes de que
la IA se pare. Si cancelas, tus datos se conservan y puedes volver a
contratar cuando quieras.

## Tus datos

- Cada empresa solo ve sus propios datos.
- Los emails se borran solos pasado el plazo que elijas (en *Empresa y
  usuarios*, "Conservar los emails"). También puedes borrar un email concreto.
- Todo lo que hace cada persona (aprobar, editar, cambiar ajustes) queda en
  un **registro de auditoría** (sección *Auditoría*) que nadie puede modificar.
- La documentación se procesa en los servidores de la plataforma; a la IA
  solo le llegan el email y los fragmentos que necesita para responder.
