# Auditoría de hardening y desbloqueo CI — HIPOCRATIQ

Fecha: 29/09/2026  
Rama: `audit/fase3-fase6-hardening-20260929`  
Base: `develop`

## 1. Diagnóstico del bloqueo

Los jobs recientes de Backend, Frontend y Mobile terminaban antes de ejecutar el primer step:

- `runner_name` vacío;
- `steps=[]`;
- ejecución de pocos segundos;
- los reintentos reproducían el mismo patrón.

Se comparó la última ejecución verde con las primeras rojas y los YAML eran idénticos en el punto de transición. La evidencia no corresponde a una regresión de sintaxis del workflow.

El patrón es compatible con indisponibilidad/capacidad de GitHub-hosted runners para la cuenta. La API disponible no expone el mensaje de facturación de la cuenta, por lo que no se atribuye el bloqueo a una causa de facturación como hecho probado.

## 2. Decisión de infraestructura

VAFTEC decidió utilizar su VPS para ejecutar las pruebas directamente.

Se descartó instalar un runner permanente de GitHub Actions en el servidor productivo. En su lugar:

- GitHub Actions queda como verificación secundaria;
- los gates críticos se ejecutan mediante scripts versionados;
- PHP, Node, Playwright, Flutter, Android SDK y PostgreSQL de prueba se encapsulan en Docker;
- los checkouts de validación deben vivir separados de los checkouts productivos;
- no se cargan secretos ni bases productivas en las suites.

Guía operativa: `docs/VPS_VALIDATION.md`.

## 3. Backend — mantenimiento aplicado

- Validación estricta de `BACKUP_SCHEDULE`.
- Un cron inválido se registra y no crea una programación ambigua.
- Telemedicina exige consentimiento vigente al crear y al iniciar una sesión.
- Prueba negativa de revocación de consentimiento.
- IA epidemiológica médica utiliza únicamente pacientes:
  - conectados al médico;
  - con consentimiento epidemiológico vigente;
  - con actividad clínica real dentro de la ventana de 90 días.
- Auditoría de llamadas externas de IA completadas/fallidas.
- Prueba de regresión de cohorte activa y auditoría.
- `Dockerfile.ci` para toolchain Backend aislado.
- `scripts/validate-vps.sh` con Composer audit, Pint, PHPUnit, PostgreSQL, migraciones, UAT, performance, health y build productivo.
- El PostgreSQL de prueba no publica el puerto fijo 5432 sobre producción.
- `scripts/deploy-production.sh` con backup/verificación, fast-forward, migraciones, health y rollback de código.

## 4. Web — mantenimiento aplicado

- Se eliminó la entrada duplicada de `develop`.
- `workflow_dispatch` y `concurrency` quedan disponibles en GitHub Actions.
- E2E completo se reserva para PR/main/manual en GitHub-hosted.
- Cleanup de procesos E2E.
- `front_web/Dockerfile.ci` encapsula Node 22.
- `front_web/Dockerfile.e2e` encapsula Playwright/Chromium.
- `scripts/validate-vps.sh` ejecuta audit, typecheck, regresiones, lint, build, E2E por rol, imagen productiva y smoke.
- `scripts/deploy-production.sh` actualiza el contenedor Web con rollback de código si falla el smoke.

## 5. Mobile Android — mantenimiento aplicado

- GitHub-hosted permanece como evidencia secundaria.
- iOS se mantiene como gate manual para evitar consumir macOS en cada push.
- `front_movil/Dockerfile.android-ci` encapsula Flutter 3.44.4 + Android SDK.
- `scripts/validate-mobile-vps.sh` ejecuta format/analyze/tests y genera APK/AAB de validación con checksums.
- La firma Android de publicación permanece separada y requiere keystore real.

## 6. Migraciones y rollback

Entre `main` y el candidato se agregan únicamente cuatro tablas:

- `clinical_audit_events`;
- `patient_consents`;
- `clinical_codings`;
- `imaging_studies`.

No existen operaciones destructivas en `up()` para estas migraciones. El rollback automático de deploy se limita al código. Un restore de PostgreSQL requiere confirmación humana y el ID exacto del backup.

## 7. Interoperabilidad / imagenología

El estado actual ya no es “sin imagenología”:

- existe workflow médico de `ImagingStudy`;
- configuración para DICOMweb/visor;
- auditoría de estudios;
- recurso FHIR R5 `ImagingStudy`.

Esto significa **DICOM/PACS-ready a nivel de integración y metadatos**, no un PACS/VNA productivo. La conexión real a PACS/DICOMweb sigue dependiendo del proveedor y credenciales externas.

## 8. Facturación

El core dispone de:

- adapter `BillingGateway`;
- idempotency key saliente;
- pagos manuales auditados;
- reembolsos manuales serializados con row lock.

El adapter HTTP actual exige respuesta síncrona `succeeded`. No existe webhook provider-specific dentro del monolito. Antes del go-live de cobro real debe definirse si los webhooks pertenecen al servicio externo indicado por `BILLING_HTTP_ENDPOINT` o al core HIPOCRATIQ; no se implementa un webhook genérico sin definir contrato/proveedor.

## 9. Gate antes de integrar a develop

La rama técnica no debe integrarse hasta:

1. revisión final del diff;
2. scripts VPS sintácticamente válidos;
3. checklist/auditoría coherentes;
4. autorización explícita para merge a `develop`.

Después del merge a `develop`, en VPS aislado:

1. Backend verde;
2. Web verde;
3. Playwright E2E verde;
4. Android verde;
5. UAT clínico/consentimiento verde;
6. migraciones PostgreSQL verdes;
7. backup creado/verificado y restore ensayado en entorno controlado;
8. sin P0 abiertos.

Solo entonces se promueve `develop` a `main`.

## 10. Estado

- `develop`: sin modificar por esta auditoría.
- `main`: sin modificar.
- Backend PR #39: draft hacia `develop`.
- Frontend PR #40: draft hacia `develop`.


## 11. Hallazgos finales de auditoría

### Seguridad de exposición
- Se detectó y corrigió una publicación accidental del Backend como `8000:8000`.
- El candidato conserva `127.0.0.1:8000:8000`, coherente con la topología productiva detrás del proxy.
- PostgreSQL y Redis permanecen ligados a loopback.

### Restore probado por diseño
- El gate VPS ahora ejecuta un ciclo completo en PostgreSQL 17 aislado:
  1. crea dato centinela;
  2. genera backup;
  3. verifica manifiesto SHA-256;
  4. elimina el dato;
  5. restaura;
  6. confirma recuperación del dato centinela.
- No se toca la base productiva.

### Web / E2E
- El fake backend E2E escucha en `0.0.0.0` dentro del contenedor para permitir comunicación por red Docker.
- El tráfico sigue aislado en la red temporal del gate.

### Branding Mobile
- Wordmark autenticación Mobile = mismo blob Git que el login Web:
  `bed9373c04b9d4ae192f19901605ac5400dd3a02`.
- Launcher Mobile = mismo blob Git que el isotipo Web oficial:
  `43a82fe44ddbc3b6c96a40e104d1e0c21a18eeec`.
- El gate Mobile compara byte a byte ambos pares antes de compilar.

### Android release
- Se añadió firma parametrizada por variables de entorno, sin credenciales en Git.
- `scripts/build-android-release.sh` genera APK y AAB release únicamente cuando existen:
  - `ANDROID_KEYSTORE_PATH`;
  - `ANDROID_KEYSTORE_PASSWORD`;
  - `ANDROID_KEY_ALIAS`;
  - `ANDROID_KEY_PASSWORD`.
- Los artefactos incluyen SHA-256.

## 12. Estado del candidato

- Backend audit branch: sin divergencia respecto a `develop` (solo commits por delante).
- Frontend/Mobile audit branch: sin divergencia respecto a `develop`.
- PR Backend #39: draft, mergeable.
- PR Frontend #40: draft, mergeable.
- `main`: no modificada por este hardening.
- Gate pendiente: ejecución real de Backend/Web/Android en checkout aislado del VPS.
