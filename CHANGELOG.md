# Historial de cambios de Hostal Rincón

El proyecto usa versionamiento semántico (`MAJOR.MINOR.PATCH`). Incrementa `MAJOR` para cambios incompatibles a partir de `1.0.0`, `MINOR` para funcionalidades compatibles y `PATCH` para correcciones compatibles. Durante el desarrollo se usa `0.x` y pueden existir cambios incompatibles entre versiones menores. `1.0.0` se reserva para la primera entrega estable validada.

Registra los cambios en la versión prevista. Al cerrar una entrega, agrega la fecha en formato `AAAA-MM-DD` y etiqueta el commit de la entrega con `vMAJOR.MINOR.PATCH`.

## 0.5.0 (prevista)

- **Cambiar de sesión** ofrece perfiles guardados de forma opcional en el navegador compartido. Cada perfil conserva solo el nombre y el correo, y pide la contraseña al entrar. [HR-8](https://nexodigital.youtrack.cloud/issue/HR-8)
- El inicio de sesión desactiva **Recordarme**, descarta las cookies persistentes antiguas y protege el historial autenticado al salir o entregar el turno. Los formularios solicitan desactivar el autocompletado; el guardado y el relleno del gestor de contraseñas se controlan en el navegador del equipo.

## 0.4.0 (prevista)

### Cierre de caja

- La administradora puede asignar el turno y registrar cortes independientes. El historial identifica al responsable de caja y a quien confirmó el conteo.
- La persona saliente registra el efectivo entregado y la entrante registra cuánto recibe. El conteo de recepción abre la caja del nuevo turno. Las diferencias requieren una observación y ajustan el saldo al efectivo contado. [HR-15](https://nexodigital.youtrack.cloud/issue/HR-15)
- Solo la persona responsable de la caja abierta puede registrar y confirmar movimientos. La administradora puede registrar movimientos y cortes incluso durante el relevo.
- Turno tiene su propia entrada en el menú. Los operadores consultan únicamente los movimientos de su turno en curso y la administradora puede consultar los de cualquier fecha.
- Durante un turno abierto, los empleados solo acceden a Turno y, si son responsables de la caja, también a Movimientos. El cierre administrativo finaliza el turno y registra el efectivo, el motivo, el responsable y quién lo autorizó.
- Cuando la caja queda libre, un empleado puede contar el efectivo inicial y abrir su propio turno. Hasta que lo abra, solo puede acceder a Turno y cerrar sesión.
- La administradora puede filtrar el historial de caja y documentar la resolución de los faltantes, incluidos los descuentos ya aplicados. El registro de resolución no cambia el saldo de caja.

### Movimientos y QA

- Movimientos e Historial de caja usan tablas de shadcn-vue en escritorio y listas compactas en móvil. Movimientos permite combinar rango de fechas, tipo, autor, estado y cuenta o efectivo.
- La administradora ve una franja con el responsable de la caja y el estado del turno.
- Staging puede cargar dos meses de operación ficticia con turnos de ocho horas, reemplazo de un empleado, efectivo de hasta USD 200, excedentes al banco y faltantes pendientes y resueltos. `StagingSeeder` requiere una base de operación vacía y una contraseña de QA configurada en el servidor.

## 0.3.0 (prevista)

### Adelantos y empleados

- Permite crear y editar empleados desde Adelantos usando sus cuentas de operador existentes; editar no cambia la contraseña salvo que se ingrese una nueva.
- Añade filtro por mes calendario y una acción para limpiar los filtros del historial.
- Destaca visualmente los importes y registros pendientes de descuento.
- No agrega una tabla de empleados. [HR-13](https://nexodigital.youtrack.cloud/issue/HR-13)

## 0.2.0 (prevista)

### Movimientos de caja

- Registra cobros en efectivo y ofrece **Guardar** o **Guardar y confirmar** para transferencias. Los cobros por transferencia identifican el banco de origen y la cuenta de destino. El voucher es único por banco de origen. [HR-14](https://nexodigital.youtrack.cloud/issue/HR-14)
- Registra egresos en efectivo o desde una cuenta bancaria a nombre del usuario de la sesión. El historial se consulta por fecha. [HR-12](https://nexodigital.youtrack.cloud/issue/HR-12)
- La administradora registra los retiros para depósito. El importe pasa de caja a tránsito y se acredita en banco con voucher o vuelve a caja al devolverlo. El traslado conserva el total. [HR-11](https://nexodigital.youtrack.cloud/issue/HR-11)

## 0.1.0 (staging · 2026-11-24)

### Adelantos

- Permite a los administradores registrar adelantos en USD para empleados que ya tienen una cuenta en el sistema. [PR #1](https://github.com/bvelastegui/hostal-rincon/pull/1)
- Bloquea registros que elevarían el saldo pendiente de un empleado por encima de USD 300. Al pagar el sueldo, el administrador puede marcar todos sus adelantos pendientes como descontados. [PR #1](https://github.com/bvelastegui/hostal-rincon/pull/1)

### Efectivo y bancos

- La administradora configura los saldos iniciales en USD, registra cuentas y activa o desactiva los bancos admitidos.
- Las cuentas conservan su historial al desactivar su banco. El alias de una cuenta sigue siendo editable después de bloquear su saldo inicial. [HR-10](https://nexodigital.youtrack.cloud/issue/HR-10)
- La tarjeta de configuración inicial del Panel muestra el avance de efectivo, banco y cuenta bancaria. La administradora puede ocultarla al completar los tres pasos. Vuelve a aparecer si surge un pendiente. [PR #4](https://github.com/bvelastegui/hostal-rincon/pull/4)

### Pruebas y despliegue

- Ejecuta validaciones de código y pruebas con Pest en GitHub Actions. Despliega a staging para QA cuando `main` pasa CI y el despliegue está habilitado. Incluye datos de prueba para dos empleados. [PR #2](https://github.com/bvelastegui/hostal-rincon/pull/2)

### Interfaz y acceso privado

- Unifica el ancho de Panel, Adelantos y Ajustes. Incorpora navegación inferior en móvil, pestañas de ajustes y cierre de sesión desde Perfil. [PR #3](https://github.com/bvelastegui/hostal-rincon/pull/3)
- Retira la eliminación de cuentas desde Perfil. La página principal dirige a los visitantes al inicio de sesión y el sitio solicita a los buscadores que eviten el rastreo y la indexación. [PR #3](https://github.com/bvelastegui/hostal-rincon/pull/3)
- Actualiza el logo para los modos claro y oscuro y traduce la interfaz y los mensajes de validación al español de Ecuador (`es_EC`). [PR #3](https://github.com/bvelastegui/hostal-rincon/pull/3)
