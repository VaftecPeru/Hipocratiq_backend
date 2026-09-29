# HIPOCRATIQ — Validación directa y aislada en VPS

Fecha: 29/09/2026

## Objetivo

Usar el VPS VAFTEC para validar Backend, Web y Android sin depender de minutos adicionales de GitHub-hosted runners y sin convertir el servidor productivo en un runner permanente de GitHub Actions.

## Principio de aislamiento

Las pruebas deben ejecutarse desde checkouts separados de producción, por ejemplo:

- `/srv/hipocratiq-ci/backend`
- `/srv/hipocratiq-ci/frontend`

No ejecutar suites de desarrollo dentro de:

- `/srv/hipocratiq/backend`
- `/srv/hipocratiq/frontend`

Los scripts de validación encapsulan toolchains y dependencias dentro de Docker. No reutilizan la base de datos productiva, no cargan secretos productivos y no requieren instalar PHP, Node, Playwright, Flutter ni Android SDK en el host.

## Backend

Desde el checkout aislado del Backend:

```bash
bash scripts/validate-vps.sh
```

Valida:

- Composer validate/audit;
- Laravel Pint;
- PHPUnit completo;
- PostgreSQL 17 aislado;
- migraciones desde cero;
- UAT clínico;
- consentimiento/telemedicina;
- privacidad y auditoría de IA epidemiológica;
- performance smoke;
- `route:list`;
- health contract;
- Docker Compose;
- build de imagen productiva;
- disponibilidad de `pg_dump`/`pg_restore`.

La evidencia se guarda por defecto en:

`/tmp/hipocratiq-validation/backend-<timestamp>.log`

## Web

Desde el checkout aislado del Frontend:

```bash
bash scripts/validate-vps.sh
```

Valida:

- npm audit HIGH+;
- TypeScript;
- regresiones Node;
- ESLint;
- Next build;
- Playwright E2E por rol;
- build de imagen productiva;
- smoke del contenedor;
- Compose;
- sintaxis del script de deploy.

La evidencia se guarda en:

`/tmp/hipocratiq-validation/frontend-<timestamp>.log`

## Android

Desde el checkout aislado del Frontend:

```bash
bash scripts/validate-mobile-vps.sh
```

La imagen `front_movil/Dockerfile.android-ci` encapsula Flutter 3.44.4 y Android SDK.

Valida:

- dart format;
- flutter analyze;
- flutter test;
- launcher icons;
- APK de validación;
- AAB de validación;
- SHA-256 de artefactos.

Los artefactos se guardan en:

`/tmp/hipocratiq-validation/android-<timestamp>/`

Estos binarios son de validación. La publicación en Google Play requiere firma release real.

## Flujo de ramas

1. Trabajar en rama técnica.
2. Auditar diff.
3. Integrar a `develop` solo con autorización.
4. En el checkout CI del VPS, actualizar `develop` y ejecutar los tres gates.
5. Si Backend + Web + E2E + Android + UAT quedan verdes y no hay P0, solicitar/promover `develop` a `main`.
6. Ejecutar deploy manual Backend y luego Frontend mediante los scripts versionados.
7. Ejecutar smoke/UAT de producción.
8. Generar artefactos Android release cuando la firma esté configurada.

## Deploy de producción

Después del merge autorizado a `main`:

Backend:

```bash
cd /srv/hipocratiq/backend
bash scripts/deploy-production.sh
```

Frontend:

```bash
cd /srv/hipocratiq/frontend
bash scripts/deploy-production.sh
```

El Backend crea y verifica un backup antes de migrar. El restore de base de datos nunca se ejecuta automáticamente.

## Requisitos del VPS

El host solo necesita para los gates:

- Git;
- Docker Engine;
- Docker Compose plugin;
- curl.

Se recomienda reservar espacio suficiente para imágenes temporales de Backend, Playwright y Android. Los scripts eliminan sus imágenes y contenedores temporales al finalizar.

## iOS

El VPS Linux no puede compilar una release iOS con Xcode. iOS queda como track independiente:

- validación técnica en macOS;
- certificado Apple;
- provisioning profile;
- IPA/TestFlight.

No debe llamarse “APK de iOS”.
