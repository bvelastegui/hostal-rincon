# Historial de cambios de Hostal Rincón

El proyecto usa versionamiento semántico (`MAJOR.MINOR.PATCH`). Incrementa `MAJOR` para cambios incompatibles a partir de `1.0.0`, `MINOR` para funcionalidades compatibles y `PATCH` para correcciones compatibles. Durante el desarrollo se usa `0.x` y pueden existir cambios incompatibles entre versiones menores. `1.0.0` se reserva para la primera entrega estable validada.

Registra los cambios en la versión prevista. Al cerrar una entrega, agrega la fecha en formato `AAAA-MM-DD` y etiqueta el commit de la entrega con `vMAJOR.MINOR.PATCH`.

## 0.1.0 (prevista)

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
