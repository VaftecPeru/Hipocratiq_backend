# Auditoría post-merge y gate VPS — 2026-09-29

## Estado Git
- Backend PR #39 integrado a `develop`.
- Frontend/Mobile PR #40 integrado a `develop`.
- Backend develop: `e0e1efb729a65fafeae8a4bd0c0e9386775f654b`.
- Frontend develop: `0789ecd12a2a767a08de44091586be2ef16d5b2b`.
- `main` permanece sin cambios.

## Fuente de verdad del release
VAFTEC usa el VPS como gate principal. GitHub-hosted Actions queda secundario y opcional.

## Gates obligatorios
1. Backend: Composer audit, Pint, PHPUnit, PostgreSQL 17, migrate:fresh.
2. Restore: backup real aislado, SHA-256, borrado de centinela, restore y recuperación.
3. UAT: Phase4ClinicalUat, DoctorWorkspace y ClinicEpidemiologyConsent.
4. Web: npm audit, typecheck, tests, lint, Next build.
5. E2E: Playwright por roles con fake backend aislado.
6. Android: format, analyze, tests, launcher, APK y AAB de validación.
7. Sin P0/P1 bloqueantes.

## Mantenimiento posterior
- PR Backend #40: omite GitHub-hosted CI automático salvo activación/manual.
- PR Frontend #41: mismo criterio y build release Android Dockerizado.
- Estos PR permanecen draft hasta validación real.

## Bloqueo operativo actual
El dispositivo remoto autorizado `VAFTEC` está offline. No se ejecutarán gates ni promoción a `main` hasta recuperar acceso al VPS.

## Regla de promoción
Solo después de gates VPS verdes:
`develop -> main` -> deploy Backend/Web -> smoke/health -> UAT productivo -> build APK/AAB release firmado.


## Actualización de auditoría — 2026-10-02

### Evidencia validada en VPS
- Producción permaneció activa durante las pruebas aisladas.
- Suite backend: 358 pruebas aprobadas y 3 pruebas Redis omitidas únicamente por no disponer de Redis en ese contenedor de test.
- PostgreSQL 17: migraciones completas desde cero.
- Backup: creación, SHA-256, verificación y restore aislado completados.
- UAT clínico explícito: aprobado para Phase4ClinicalUat, DoctorWorkspace y ClinicEpidemiologyConsent.

### Incidente Gate 8 — causa raíz
El primer performance smoke arrancó la aplicación dentro de Docker con `DB_HOST=127.0.0.1` y `DB_PORT=5433`. Desde el contenedor de aplicación, ese loopback no corresponde al PostgreSQL QA, por lo que la conexión fue rechazada.

En el segundo intento PostgreSQL QA quedó saludable, pero la imagen `Dockerfile.ci` usa por diseño `CMD ["php", "-v"]`. Al levantarla sin sobrescribir el comando, el contenedor imprimió la versión de PHP y terminó antes de escuchar en el puerto QA.

### Remediación
`scripts/vps-qa-gate.sh` ahora:
1. crea una red Docker QA única por ejecución;
2. levanta PostgreSQL 17 aislado;
3. usa el nombre DNS del contenedor PostgreSQL y puerto interno 5432;
4. ejecuta migrate + seed + backup/verify/restore;
5. levanta explícitamente Laravel con `php artisan serve --host=0.0.0.0 --port=8000`;
6. publica únicamente en `127.0.0.1:18080` del VPS;
7. valida liveness/readiness y ejecuta `scripts/performance-smoke.sh`;
8. elimina contenedores y red QA mediante trap al finalizar.

### Estado de promoción
`main` y los contenedores de producción continúan fuera del alcance de este gate. La promoción a `main` sigue condicionada a Backend QA verde, Web/E2E verde y Android APK+AAB verde.
