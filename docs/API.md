# Referencia de la API

La especificación formal OpenAPI 3.1 está en [../../documentacion/openapi.yaml](../../documentacion/openapi.yaml). Este documento añade el flujo operativo y ejemplos que no caben cómodamente en el esquema.

## Base URL y autenticación

Para desarrollo directo:

```text
http://127.0.0.1:8000/api
```

El frontend Next.js expone el backend mediante `/backend`, por lo que desde el navegador se usan rutas como `/backend/v1/auth/login`.

Todas las peticiones JSON deben incluir:

```http
Accept: application/json
Content-Type: application/json
```

Los clientes web usan cookies de sesión Sanctum. Antes del primer `POST`, solicita `GET /sanctum/csrf-cookie`, conserva las cookies y envía `X-XSRF-TOKEN`. El cliente del frontend ya encapsula este paso.

Los clientes móviles reciben un token Bearer al verificar un OTP:

```http
Authorization: Bearer <access_token>
```

Las rutas protegidas requieren `auth:sanctum` y una sesión de autenticación activa. Las rutas de administración requieren además el permiso `manage-platform`.

## Respuestas y errores

Las respuestas exitosas tienen esta forma general:

```json
{
  "success": true,
  "data": {}
}
```

Las validaciones usan el formato Laravel:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field": ["Mensaje de validación"]
  }
}
```

Estados relevantes:

| Estado | Uso |
| ---: | --- |
| `201` | Draft de clínica o médico creado |
| `202` | OTP solicitado o reenviado; el envío continúa como desafío |
| `200` | Operación exitosa |
| `204` | Logout exitoso sin contenido |
| `401` | No autenticado (`UNAUTHENTICATED`) |
| `403` | Sin permisos o acceso a clínica |
| `419` | CSRF inválido (`CSRF_TOKEN_MISMATCH`) |
| `422` | Payload inválido, draft expirado, plan faltante u OTP inválido |
| `429` | Rate limit excedido; respeta `Retry-After` |
| `503` | Proveedor OTP no disponible (`OTP_DELIVERY_UNAVAILABLE`) o Redis requerido no disponible en health |

## Política OTP

La configuración predeterminada es:

- Expiración: 5 minutos.
- Máximo de intentos de código: 5.
- Máximo de reenvíos: 5 por desafío.
- Cooldown entre reenvíos: 60 segundos.
- Código: exactamente 6 dígitos.

Al solicitar o reenviar un desafío, `data` incluye:

```json
{
  "challenge_id": "uuid",
  "next_step": "verify_otp",
  "expires_in": 300,
  "resend_after": 60,
  "resends_remaining": 5
}
```

`expires_in`, `resend_after` y `resends_remaining` son valores dinámicos; el cliente no debe asumir que permanecen constantes después de un reenvío.

## Endpoints públicos

| Método | Ruta | Descripción |
| --- | --- | --- |
| `GET` | `/api` | Metadatos del servicio |
| `GET` | `/api/health` | Comprueba Redis configurado para caché, limitador, locks y sesión |
| `GET` | `/api/v1/auth/register/clinic/plans` | Planes activos y seleccionables públicamente |
| `GET` | `/api/v1/auth/register/doctor/clinics` | Clínicas activas con suscripción activa |
| `GET` | `/api/v1/auth/register/patient/clinics` | Clínicas activas disponibles para pacientes |

Las listas de clínicas devuelven `id`, `name`, `country_code` y `address`, ordenadas por nombre.

## Registro de clínica administradora

### 1. Crear draft

`POST /api/v1/auth/register/clinic/draft`

Payload:

```json
{
  "clinic_name": "Clínica Central",
  "tax_id": "20123456789",
  "country_code": "PE",
  "contact_phone": "999111222",
  "address": "Av. Principal 123",
  "admin_name": "Ana Pérez",
  "admin_email": "ana@example.com",
  "admin_phone_number": "999333444",
  "password": "UnaClaveSegura123!",
  "password_confirmation": "UnaClaveSegura123!",
  "terms_accepted": true
}
```

La respuesta es `201`, establece la cookie HttpOnly `clinic_registration` durante 30 minutos y devuelve `next_step: select_plan`. La contraseña nunca se guarda en el draft en texto plano.

### 2. Seleccionar plan

`POST /api/v1/auth/register/clinic/plan`

Requiere la cookie del paso anterior:

```json
{
  "plan": "basic",
  "billing_cycle": "monthly"
}
```

`plan` puede ser `basic` o `professional`; `billing_cycle` puede ser `monthly` o `annual`. La respuesta crea el desafío OTP y devuelve `next_step: verify_otp`.

### 3. Verificar OTP

`POST /api/v1/auth/register/clinic/verify-otp`

```json
{
  "challenge_id": "uuid-del-desafio",
  "code": "123456"
}
```

Solo después de plan válido y OTP correcto se crean la cuenta, clínica, membresía y suscripción. La cookie `clinic_registration` se elimina al finalizar.

### Reenviar OTP

`POST /api/v1/auth/register/clinic/resend-otp`

No requiere body; requiere la cookie del draft. Está sujeto al cooldown y al máximo de cinco reenvíos.

## Registro de médico

### Clínicas disponibles

`GET /api/v1/auth/register/doctor/clinics`

Solo devuelve clínicas activas con cobertura de suscripción vigente. Un médico de clínica debe seleccionar una de ellas.

### Crear draft

`POST /api/v1/auth/register/doctor/draft`

Payload base:

```json
{
  "doctor_name": "Carlos Ruiz",
  "doctor_email": "carlos@example.com",
  "country_code": "PE",
  "doctor_phone_number": "999555666",
  "medical_license": "CMP-12345",
  "specialty": "Cardiología",
  "practice_type": "independent",
  "clinic_id": null,
  "password": "UnaClaveSegura123!",
  "password_confirmation": "UnaClaveSegura123!",
  "terms_accepted": true
}
```

`practice_type` es `clinic` o `independent`. Para `clinic`, `clinic_id` es obligatorio y debe pertenecer a una clínica activa con plan vigente. Para `independent`, `clinic_id` debe ser `null` y la respuesta devuelve `next_step: select_plan`.

Un médico de clínica recibe OTP inmediatamente porque la clínica cubre su plan. Un médico independiente debe completar el paso de plan antes de recibir el OTP.

La cookie del flujo es `doctor_registration`.

### Seleccionar plan independiente

`POST /api/v1/auth/register/doctor/plan`

```json
{
  "plan": "professional",
  "billing_cycle": "annual"
}
```

El plan es obligatorio para finalizar un registro independiente. Sin plan no se crean registros de dominio.

### Verificar y reenviar OTP

Las rutas son:

```text
POST /api/v1/auth/register/doctor/verify-otp
POST /api/v1/auth/register/doctor/resend-otp
```

La verificación usa `challenge_id` y `code` como en el registro de clínica.

## Registro de paciente

### Clínicas disponibles

`GET /api/v1/auth/register/patient/clinics`

El paciente debe elegir una clínica activa. El plan no forma parte de este formulario y su selección se difiere a otro apartado.

### Crear registro y solicitar OTP

`POST /api/v1/auth/register/patient`

```json
{
  "name": "Laura Gómez",
  "email": "laura@example.com",
  "country_code": "PE",
  "phone_number": "999777888",
  "clinic_id": 12,
  "password": "Opcional123!",
  "password_confirmation": "Opcional123!",
  "terms_accepted": true
}
```

`email` y `password` son opcionales. `clinic_id` es obligatorio y debe apuntar a una clínica activa. La respuesta es `202` y devuelve el desafío OTP.

### Verificar teléfono

`POST /api/v1/auth/register/verify-otp`

```json
{
  "challenge_id": "uuid-del-desafio",
  "code": "123456"
}
```

La respuesta inicia una sesión de paciente y devuelve `next_step: select_plan` junto con `plan_selection_deferred: true`. Esto indica que el plan se elegirá después, no durante el registro.

### Reenviar OTP de paciente

`POST /api/v1/auth/otp/resend`

```json
{
  "challenge_id": "uuid-del-desafio"
}
```

## Login y OTP genérico

### Login web tradicional

`POST /api/v1/auth/login`

```json
{
  "country_code": "PE",
  "phone_number": "999111222",
  "password": "UnaClaveSegura123!"
}
```

La respuesta es `202` con `challenge_id`; finaliza con `POST /api/v1/auth/login/verify-otp` usando `challenge_id` y `code`.

### Solicitar OTP por propósito

`POST /api/v1/auth/otp/request`

Los propósitos son `registration`, `login` y `password_reset`. El cliente puede ser `web` o `mobile`; el contexto puede ser `patient`, `staff`, `clinic_admin` o `doctor`.

Ejemplo de registro móvil:

```json
{
  "purpose": "registration",
  "client_type": "mobile",
  "actor_context": "patient",
  "name": "Laura Gómez",
  "country_code": "PE",
  "phone_number": "999777888",
  "clinic_id": 12,
  "terms_accepted": true,
  "device_name": "iPhone",
  "device_id": "device-id"
}
```

Para `login` o `password_reset`, usa `identifier` con correo o teléfono E.164. Un reset de contraseña requiere `password` y `password_confirmation` al verificar.

### Verificar OTP genérico

`POST /api/v1/auth/otp/verify`

```json
{
  "purpose": "login",
  "challenge_id": "uuid-del-desafio",
  "code": "123456"
}
```

En clientes móviles la respuesta incluye `token_type`, `access_token`, `expires_at` y `user`. En clientes web se inicia la sesión cookie.

## Sesión y endpoints protegidos

Todas estas rutas requieren autenticación:

| Método | Ruta | Requisito adicional |
| --- | --- | --- |
| `GET` | `/api/v1/auth/me` | Sesión activa |
| `POST` | `/api/v1/auth/session/renew` | Sesión web activa |
| `POST` | `/api/v1/auth/logout` | Sesión o token actual |
| `POST` | `/api/v1/auth/logout-all` | Revoca sesiones y tokens |
| `GET` | `/api/v1/auth/clinic/dashboard` | Acceso a clínica activa |
| `GET` | `/api/v1/auth/admin/clinics` | Permiso `manage-platform` |

`/me` devuelve usuario, roles, perfiles, contexto y clínica/plan relacionados. `logout-all` revoca tokens móviles y sesiones activas.

## Límites de tráfico

Los limitadores son distribuidos mediante Redis y devuelven `429` con `Retry-After` cuando corresponde.

| Grupo | Límites principales |
| --- | --- |
| `auth-register` | 10/min por IP, 5/h por teléfono |
| `auth-login` | 10/min por IP, 5/min por teléfono |
| `auth-otp` | 10/min por IP, 5/min por desafío |
| `auth-otp-request` | 10/min por IP, 5/h por identidad |
| `clinic-registration` | 8/min por IP, 3/h por identidad |
| `registration-plan` | 10/min por IP, 5/h por draft |
| `clinic-registration-otp` | 10/min por IP, 5/h por draft |

Además del rate limiting HTTP, cada desafío OTP mantiene su propio cooldown, expiración, intentos y contador de reenvíos.

## Ejemplo de flujo con cURL

El flujo de clínica conserva la cookie de draft entre pasos:

```powershell
curl.exe -c cookies.txt -b cookies.txt -H "Accept: application/json" -H "Content-Type: application/json" `
  -X POST http://127.0.0.1:8000/api/v1/auth/register/clinic/draft `
  --data '{"clinic_name":"Clínica Central","tax_id":"20123456789","country_code":"PE","contact_phone":"999111222","address":"Av. Principal 123","admin_name":"Ana Pérez","admin_email":"ana@example.com","admin_phone_number":"999333444","password":"UnaClaveSegura123!","password_confirmation":"UnaClaveSegura123!","terms_accepted":true}'

curl.exe -c cookies.txt -b cookies.txt -H "Accept: application/json" -H "Content-Type: application/json" `
  -X POST http://127.0.0.1:8000/api/v1/auth/register/clinic/plan `
  --data '{"plan":"basic","billing_cycle":"monthly"}'

curl.exe -c cookies.txt -b cookies.txt -H "Accept: application/json" -H "Content-Type: application/json" `
  -X POST http://127.0.0.1:8000/api/v1/auth/register/clinic/verify-otp `
  --data '{"challenge_id":"<challenge_id>","code":"<codigo_de_6_digitos>"}'
```

Para pruebas locales con `SMS_DRIVER=array`, los tests usan un generador OTP fijo. No habilites `OTP_FIXED_CODE` en producción.

## Operación y troubleshooting

```powershell
docker compose ps
docker compose logs --tail=100 redis
docker compose exec -T redis sh -c 'REDISCLI_AUTH="$REDIS_PASSWORD" redis-cli ping'
php artisan config:clear
php artisan cache:clear
php artisan route:list --path=api --except-vendor
```

Si `/api/health` devuelve `503`, comprueba primero el estado del contenedor Redis, la contraseña, el puerto y las variables `REDIS_*`. No borres volúmenes de Docker en producción para resolver un problema de conexión.

## Referencias de implementación

- [Variables de entorno](../.env.example)
- [Configuración de caché](../config/cache.php)
- [Conexiones Redis](../config/database.php)
- [Configuración de sesiones](../config/session.php)
- [Rutas](../routes/api.php)
- [Pruebas Redis](../tests/Feature/RedisInfrastructureTest.php)

## Versionado y mantenimiento

La API pública se versiona con el prefijo `/v1`. Los cambios de validación, límites OTP, autenticación o forma de respuesta deben actualizar simultáneamente este documento, [openapi.yaml](../../documentacion/openapi.yaml) y las pruebas de `tests/Feature`.

### Versión 1.1.0

- Redis distribuido para caché, sesiones, locks y rate limiting.
- Registro de paciente con clínica obligatoria y plan diferido.
- Registro independiente de médico condicionado a selección de plan.
- OTP con expiración, cooldown, intentos y máximo de reenvíos documentados.
