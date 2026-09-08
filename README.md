# Hipocratiq API

Backend Laravel 13 para autenticación, registro de clínicas, médicos y pacientes, sesiones web/móviles y administración de clínicas.

La referencia completa de endpoints está en [docs/API.md](docs/API.md).

## Requisitos

- PHP 8.4 o superior.
- Composer 2.
- Extensión PHP `redis` (PhpRedis).
- Docker Desktop para PostgreSQL y Redis.
- Node.js solo si se ejecutan herramientas frontend del monorepo.

## Inicio local

Desde `back_api`:

```powershell
Copy-Item .env.example .env
composer install
php artisan key:generate
docker compose up --build -d
```

`docker compose up` inicia PostgreSQL, Redis y la API HTTP. La API queda disponible únicamente en `http://127.0.0.1:8000`. El archivo `.env` se carga en tiempo de ejecución y debe contener un `APP_KEY` generado; no se copia dentro de la imagen.

El arranque no ejecuta migraciones, seeders, workers ni cachés de configuración. Ejecuta estas operaciones manualmente solo cuando corresponda:

```powershell
docker compose exec app php artisan migrate --force --no-interaction
docker compose exec app php artisan db:seed --class=DemoUsersSeeder --force --no-interaction
```

Comprueba el estado con:

```powershell
Invoke-RestMethod http://127.0.0.1:8000/api/health | ConvertTo-Json -Depth 5
```

La configuración local de Docker utiliza PostgreSQL en `127.0.0.1:5433` y Redis en `127.0.0.1:6379`. El frontend usa `/backend` como proxy; las rutas directas del backend siempre empiezan por `/api`.

## Servicios de infraestructura

`docker compose up -d` inicia:

| Servicio | Uso | Persistencia |
| --- | --- | --- |
| API HTTP (FrankenPHP) | API Laravel para pruebas locales | Imagen de aplicación; sin workers |
| PostgreSQL 17 | Datos de aplicación | Volumen `postgres-data` |
| Redis 8.2.7 | Caché, drafts, sesiones, locks y rate limiting | AOF + volumen `redis-data` |

Redis utiliza bases lógicas separadas para evitar interferencias:

| Base | Conexión | Uso |
| ---: | --- | --- |
| 0 | `default` | Reserva para integraciones generales |
| 1 | `cache` | Caché y drafts de registro |
| 2 | `session` | Sesiones Laravel |
| 3 | `limiter` | Rate limiting distribuido |
| 4 | `locks` | Locks de concurrencia |

Verificación rápida de Redis:

```powershell
docker compose ps redis
docker compose exec -T redis sh -c 'REDISCLI_AUTH="$REDIS_PASSWORD" redis-cli ping'
```

Debe responder `PONG` y el contenedor debe aparecer como `healthy`.

No se utiliza fallback automático a base de datos para drafts, locks ni limitadores: en esos flujos es preferible fallar y no crear estados inconsistentes.

## Configuración importante

Las variables de ejemplo están en [.env.example](.env.example). En producción deben inyectarse como secretos o variables del entorno, no copiarse desde el ejemplo.

| Variable | Valor local | Función |
| --- | --- | --- |
| `CACHE_STORE` | `redis` | Caché y locks de drafts |
| `CACHE_LIMITER_STORE` | `redis_limiter` | Rate limiting distribuido |
| `SESSION_DRIVER` | `redis` | Sesiones compartidas entre réplicas |
| `SESSION_STORE` | `redis_session` | Store aislado para sesiones |
| `SESSION_ENCRYPT` | `true` | Cifra los datos de sesión |
| `OTP_TTL_MINUTES` | `5` | Expiración del código OTP |
| `OTP_MAX_ATTEMPTS` | `5` | Intentos de verificación por desafío |
| `OTP_MAX_RESENDS` | `5` | Reenvíos máximos por desafío |
| `OTP_RESEND_SECONDS` | `60` | Cooldown entre reenvíos |
| `SMS_DRIVER` | `array` | Simulador local; usar `twilio` en producción |
| `OTP_HASH_KEY` | — | Clave dedicada recomendada para hash OTP |

`OTP_FIXED_CODE` solo debe usarse en pruebas locales y nunca en producción. En producción se requiere `APP_DEBUG=false`, `SMS_DRIVER=twilio`, una `OTP_HASH_KEY` dedicada y secretos Twilio válidos.

## Flujos principales

- Clínica: crear draft → seleccionar plan → verificar OTP. Sin plan o sin OTP correcto no se crean usuario, clínica ni suscripción.
- Médico de clínica: crear draft con una clínica activa que tenga cobertura → verificar OTP. La cobertura de la clínica determina el plan.
- Médico independiente: crear draft → seleccionar plan → verificar OTP. El plan es obligatorio antes de finalizar.
- Paciente: elegir una clínica activa → crear registro → verificar OTP. El plan no se muestra ni se elige durante el registro; queda diferido para el apartado posterior.
- Login: contraseña → OTP → sesión web o token móvil.

Los detalles de payloads, estados HTTP, errores y límites están en [docs/API.md](docs/API.md).

## Pruebas y calidad

```powershell
php artisan test --compact
vendor/bin/pint --dirty --format agent
composer validate --strict --no-check-publish
composer check-platform-reqs --no-dev
composer audit --locked --no-dev
```

Las pruebas de aplicación fuerzan `array` para caché, limitador y sesión, de modo que la suite sea reproducible. `RedisInfrastructureTest` valida adicionalmente, cuando Redis está disponible, round-trip, aislamiento de stores, locks, limiter, sesiones y health check.

Pruebas focalizadas:

```powershell
php artisan test --compact tests/Feature/ClinicRegistrationTest.php
php artisan test --compact tests/Feature/DoctorRegistrationTest.php
php artisan test --compact tests/Feature/Auth/PatientRegistrationTest.php
php artisan test --compact tests/Feature/Auth/OtpAuthenticationTest.php
php artisan test --compact tests/Feature/RedisInfrastructureTest.php
```

## Comandos operativos

```powershell
php artisan route:list --path=api --except-vendor
php artisan config:clear
php artisan cache:clear
docker compose logs -f redis
```

Antes de desplegar una nueva versión, ejecuta las migraciones y verifica `/api/health`. En producción Redis debe estar en una red privada, con ACL/TLS y una política de memoria monitorizada.

## Documentación relacionada

- [Referencia de API](docs/API.md)
- [Especificación OpenAPI 3.1](../documentacion/openapi.yaml)
- [Laravel](https://laravel.com/docs/13.x)
- [Redis en Laravel](https://laravel.com/docs/13.x/redis)
- [Rate limiting](https://laravel.com/docs/13.x/rate-limiting)
