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
