# HIPOCRATIQ — Hoja de ejecución P0 antes de publicar (26/10/2026)


## Corte verificado — PR #108 fusionado a develop (26/10/2026)

**Producción: NO-GO.** Este corte prevalece sobre los SHAs y estados históricos de secciones posteriores.

| Control | Evidencia actual |
| --- | --- |
| Backend PR [#108](https://github.com/VaftecPeru/Hipocratiq_backend/pull/108) | **MERGED** a develop, merge SHA `4e08b5a173f59b039ed8b40ee68866dda09f4fe1`; HEAD aprobado `434aa017c0b26799757b74729ce118a591044f5c`. |
| CI previo al merge | **6/6 SUCCESS**, todos sobre el HEAD exacto, incluido redacted Git history [#40393503926](https://github.com/VaftecPeru/Hipocratiq_backend/actions/runs/40393503926). |
| CI posterior en develop | [#40396116781](https://github.com/VaftecPeru/Hipocratiq_backend/actions/runs/40396116781): **COMPLETED/SUCCESS**. |
| Backend release [PR #90](https://github.com/VaftecPeru/Hipocratiq_backend/pull/90) | Rama RC sincronizada por **fast-forward no forzado** a `4e08b5a173f59b039ed8b40ee68866dda09f4fe1`, **DRAFT**, sin fusionar a main. |
| Frontend release [PR #80](https://github.com/VaftecPeru/Hipocratiq_Frontend/pull/80) | **DRAFT**, SHA `684977a7067638c564e74a72e93647f2f5a8f9c0`, sin fusionar. |
| main Backend / Frontend | `c99c6e9391ea44986997c7a49c311b4177e6f731` / `235bb34225e51f8e93b8aa134192d7e8971e1194`: **intactas**. |
| GitHub protección/visibilidad | Ambos repos **públicos**; **cuatro ramas** develop/main sin protección efectiva (`protected=false`), sin rulesets. |
| P0 [#102](https://github.com/VaftecPeru/Hipocratiq_backend/issues/102) | **OPEN**. Auditor sanitizado halló **25 objetos históricos** de plantillas con asignaciones no vacías y **1 ruta de archivo de entorno**. No demuestra credenciales operativas, exige revisión privada y rotación si aplica. |
| P0 [#92](https://github.com/VaftecPeru/Hipocratiq_backend/issues/92) | **OPEN**. Sin prueba de OIDC/Environment protegido y AWS UAT inventario real SUCCESS con artifact sobre SHA actual; **0/36 UAT clínica real**, OTP México, restore/rollback y aceptación pendientes. |

**Cinco controles de cierre obligatorios sin omisiones:** (1) propietario y Seguridad deciden visibilidad pública y confirman auditoría privada del historial, artifacts/releases/forks, rotación/invalidez según correspondencia; (2) administrador GitHub con permisos reales instala protección de 4 ramas y Environment `hipocratiq-uat-readonly` restringido a develop con revisores efectivos y verifica lectura posterior; (3) administrador AWS revisa change set IAM OIDC de lectura, verifica cuenta/región/EC2/VPC UAT vs producción y ejecuta inventario real solamente tras aprobación; (4) equipo QA completa HTTPS/OTP MX +52, 36/36 UAT clínica y restauración/rollback aislados con evidencia; (5) cliente/PM emiten acta GO y autorización **separada** de merge main y deploy productivo.

**Regla STOP:** no cerrar #102/#92 por CI verde, no quitar DRAFT a PR #90/#80, no hacer merge a main ni mutar AWS producción. Este PR #104 continúa en revisión documental hacia develop y **no se fusiona** con esta actualización.

---


## Evidencia adicional del historial público — PR #108

- Backend `main` histórico: en `.env.example`, las asignaciones `DB_PASSWORD` y `REDIS_PASSWORD` no estaban vacías; `develop` actual las tiene vacías gracias a la remediación #106. **No hay prueba de que fueran contraseñas operativas.**
- [Backend PR #108](https://github.com/VaftecPeru/Hipocratiq_backend/pull/108) (abierto, sin merge) añade el auditor de historia Git que **no publica secretos, SHA de blobs ni rutas sensibles**. Sobre SHA `434aa017c0b26799757b74729ce118a591044f5c`, los cinco CI/UAT son **SUCCESS**, incluida [auditoría #40393503926](https://github.com/VaftecPeru/Hipocratiq_backend/actions/runs/40393503926) con artefacto sanitizado ID `12686341018`.
- **Señales del historial:** 25 objetos históricos de plantillas con asignaciones no vacías y 1 ruta de archivo de entorno. Los conteos no demuestran una credencial activa ni su descarte. [Issue P0 #102](https://github.com/VaftecPeru/Hipocratiq_backend/issues/102) sigue **OPEN** con evidencia actualizada.
- **Acción no automatizable sin responsables:** revisión privada de la validez/reutilización de valores históricos, rotación en caso de coincidencia o duda, revisión de artifacts/releases/forks, decisión documentada de visibilidad GitHub y acta de Seguridad/PM. Nunca copiar valores al issue, bitácora o logs.
- La barrera de producción [release evidence #40376745118](https://github.com/VaftecPeru/Hipocratiq_backend/actions/runs/40376745118) sigue NO-GO con nueve bloqueos, incluidos P0 #92/#102, ramas desprotegidas e inventario AWS real sin evidencia. **No convertir draft a ready ni fusionar `main`/desplegar AWS.**

---

## Corte vigente 26/10/2026 — control #107 integrado; producción NO-GO

- **Backend `develop` verificado:** `fed0b1d5a8d66637d4d584e07452770f1abcf0ec`, tras integrar [PR #107](https://github.com/VaftecPeru/Hipocratiq_backend/pull/107) con cinco checks premerge SUCCESS. El CI postmerge [#40376290175](https://github.com/VaftecPeru/Hipocratiq_backend/actions/runs/40376290175) exige confirmación separada.
- **PR #106 ya integrado** en `develop` previamente: plantilla `.env.example` sin valores DB/Redis y guard preventivo CI. El [P0 seguridad #102](https://github.com/VaftecPeru/Hipocratiq_backend/issues/102) sigue **OPEN** hasta revisión histórica/rotación privada según corresponda.
- **Protección adicional:** el release gate consulta ahora el issue #102. Solo se obtiene evidencia mínima si el P0 clínico #92 y el P0 seguridad #102 cumplen sus respectivos cierres verificados; el CI sintético no suple UAT real.
- **Backend candidato [PR #90](https://github.com/VaftecPeru/Hipocratiq_backend/pull/90):** continúa DRAFT/NO-GO. Su SHA `e18c98af93827eef6cf75e2968e648dd948673a5` es histórico: falta sincronizar el nuevo commit de release gate y repetir checks antes de solicitar GO. Frontend [PR #80](https://github.com/VaftecPeru/Hipocratiq_Frontend/pull/80) permanece DRAFT.
- **Bloqueos reales:** GitHub `main`/`develop` de los dos repos públicos `protected=false`, Environment `hipocratiq-uat-readonly` no acreditado, rol AWS OIDC / inventario UAT real no certificados, 0/36 UAT clínica, OTP +52, respaldo/restauración y autorización cliente ausentes. **Ningún merge a `main`, ningún deploy AWS**.

Las secciones inferiores conservan cortes anteriores para auditoría y NO representan SHAs actuales.

---



## Nuevo corte verificado — 26/10/2026, tras remediación P0 #106

**Producción: NO-GO.** El [Backend PR #106](https://github.com/VaftecPeru/Hipocratiq_backend/pull/106) se fusionó a `develop` en el SHA **`e18c98af93827eef6cf75e2968e648dd948673a5`**, con cinco ejecuciones CI/QA previas `SUCCESS`; el CI posterior al merge [#40374153870](https://github.com/VaftecPeru/Hipocratiq_backend/actions/runs/40374153870) debe comprobarse por separado. La plantilla `.env.example` ya deja vacíos los campos de contraseña de BD y Redis e incorpora un control CI que no imprime secretos. **No elimina el historial de Git ni certifica la rotación** de credenciales, por lo que el [P0 público #102](https://github.com/VaftecPeru/Hipocratiq_backend/issues/102) permanece OPEN; si hubo reutilización o no puede descartarse, corresponde revisión privada y rotación por un administrador, sin exponer valores.

| Control al 26/10 | Evidencia |
|---|---|
| Backend `develop` | `e18c98af93827eef6cf75e2968e648dd948673a5` |
| Frontend `develop` | `684977a7067638c564e74a72e93647f2f5a8f9c0`; CI #40271757694 SUCCESS |
| Backend release [PR #90](https://github.com/VaftecPeru/Hipocratiq_backend/pull/90) | **DRAFT**, candidato anterior `2606c86bf7addb39d2dddb17b68cafbaa0de7c1c`; quedó desfasado tras PR #106. NO actualizar automáticamente a `main` |
| Frontend release [PR #80](https://github.com/VaftecPeru/Hipocratiq_Frontend/pull/80) | **DRAFT**; `684977a7067638c564e74a72e93647f2f5a8f9c0` |
| GitHub | Ambos repositorios **públicos**; ramas `main` y `develop` con `protected=false` y sin rulesets efectivos |
| Release gate Backend [#40369034777](https://github.com/VaftecPeru/Hipocratiq_backend/actions/runs/40369034777) | **NO_GO**: 8 bloqueos reales (ambos DRAFT, cuatro ramas sin protección, issue #92 abierto, inventario AWS real ausente) |
| UAT clínica | 0/36 aprobadas en entorno real; OTP México, backup/restore y acta de cliente pendientes |

**Acción administrativa siguiente, verificable y sin falsos verdes:** usar el bootstrap aprobado del PR #105 desde una estación con credenciales de administración; configurar y leer de vuelta las cuatro protecciones, revisar política de visibilidad, restringir Environment `hipocratiq-uat-readonly` a `develop` con revisores y, solamente después, autorizar el change set del rol AWS `HIPOCRATIQ-UAT-ReadOnly`. No confundir OIDC `sub:...:environment:...` con una restricción implícita de rama. Ejecutar inventario AWS real solo con cuenta/región/EC2/VPC corroborados; el job debe terminar `SUCCESS`, no `SKIPPED`, y aportar artifact saneado inspeccionado.

**Regla STOP:** ni el merge preventivo #106 ni los pipelines sintéticos autorizan publicar. Mantener [#92](https://github.com/VaftecPeru/Hipocratiq_backend/issues/92) y [#102](https://github.com/VaftecPeru/Hipocratiq_backend/issues/102) OPEN, candidatos a `main` DRAFT y sin cambios en AWS hasta pruebas clínicas, recuperación, controles efectivos y autorización final.

---


## Actualización de control — 26/10/2026 (posterior al PR #105)

**ESTADO: NO-GO.** El PR #105 fue integrado **solo a Backend `develop`** con el bootstrap de configuración GitHub. Tener el código del bootstrap **no significa** que las protecciones, Environment o el rol AWS OIDC estén configurados.

| Verificación nueva | Evidencia y estado |
|---|---|
| Backend `develop` | `2606c86bf7addb39d2dddb17b68cafbaa0de7c1c`; CI [#40366180991](https://github.com/VaftecPeru/Hipocratiq_backend/actions/runs/40366180991) SUCCESS |
| Backend release PR #90 | Actualizado por fast-forward a `2606c86bf7addb39d2dddb17b68cafbaa0de7c1c`, **continúa DRAFT**; CI/Fullstack del RC en ejecución o sujetos a revisión final |
| Frontend `develop` y release PR #80 | `684977a7067638c564e74a72e93647f2f5a8f9c0`, **PR #80 DRAFT** |
| GitHub `main` y `develop` | `protected=false` en ambos repositorios. Repos `public`; [P0 #102](https://github.com/VaftecPeru/Hipocratiq_backend/issues/102) OPEN |
| Gate clínico | [P0 #92](https://github.com/VaftecPeru/Hipocratiq_backend/issues/92) OPEN; 0/36 casos UAT real aprobados |
| Revisión de credenciales de ejemplo | [Backend PR #106](https://github.com/VaftecPeru/Hipocratiq_backend/pull/106) en revisión; si `DB_PASSWORD`/`REDIS_PASSWORD` históricos están en uso, validar rotación privada y auditar historial, **sin revelar valores** |

### Paso administrativo ejecutable, sin atajos de producción

1. Desde una **estación confiable con permiso Administration:write**, ejecutar primero el modo offline y las pruebas de `scripts/configure_github_release_governance.py`. La [guía del PR #105](2026-10-26-github-admin-bootstrap-execution.md) detalla los comandos, las cuatro ramas, los checks y el Environment `hipocratiq-uat-readonly` con reviewer independiente.
2. Tras revisión del plan, el **administrador** ejecuta `--apply` con token admin local y frase exacta. Repetir `--verify` y `scripts/uat_github_admin_governance_readonly.py --live`; exigir evidencia de reglas **efectivamente aplicadas**, no solo YAML ni CI offline.
3. Solo con ese control, solicitar change set IAM/OIDC AWS **de lectura** independiente, verificar cuenta/región/EC2/VPC reales y ejecutar inventario AWS UAT aprobado. Ningún cambio IAM ni lectura EC2/SSM se ha realizado en esta actualización.
4. Con inventario real y entorno UAT segregado, completar HTTPS, OTP SMS MX, matriz clínica 36/36, restauración/rollback y acta de aceptación. **No quitar DRAFT a PR #90/#80, no cerrar #92/#102, no tocar `main` ni producción hasta evidencias P0.**

Los SHAs y CI en la tabla original inferior son un corte histórico previo al PR #105. No utilizarlos como prueba de versión vigente.

---

**Decisión: NO-GO. Este documento no autoriza merges a main, llamadas AWS ni el cierre del issue #92.**  
La aprobación del software en CI y las pruebas con datos sintéticos no acreditan que la infraestructura UAT, la restauración ni la aceptación clínica se hayan realizado.

## 1. Inventario verificado en GitHub (corte 26/10/2026)

| Control | Backend | Frontend |
|---|---|---|
| `develop` | `19e8bb1615e2622d562fa27c018f5b1aa0c97935` | `684977a7067638c564e74a72e93647f2f5a8f9c0` |
| `main` (no cambiar) | `c99c6e9391ea44986997c7a49c311b4177e6f731` | `235bb34225e51f8e93b8aa134192d7e8971e1194` |
| Candidato a `main` | [PR #90](https://github.com/VaftecPeru/Hipocratiq_backend/pull/90), **DRAFT** | [PR #80](https://github.com/VaftecPeru/Hipocratiq_Frontend/pull/80), **DRAFT** |
| Últimos CI de candidato | [CI #40278056712](https://github.com/VaftecPeru/Hipocratiq_backend/actions/runs/40278056712) y fullstack [#40278056699](https://github.com/VaftecPeru/Hipocratiq_backend/actions/runs/40278056699): SUCCESS | [Web #40271789388](https://github.com/VaftecPeru/Hipocratiq_Frontend/actions/runs/40271789388) y Mobile #40271789356: SUCCESS |
| Release evidence | [#40278056802](https://github.com/VaftecPeru/Hipocratiq_backend/actions/runs/40278056802): **FAIL / NO-GO esperado** | [#40271789347](https://github.com/VaftecPeru/Hipocratiq_Frontend/actions/runs/40271789347): **FAIL / NO-GO esperado** |
| Branches `develop` y `main` | `protected=false` | `protected=false` |
| Visibility GitHub API | `public` (`private=false`) | `public` (`private=false`) |

**Corrección de documentación anterior:** las guías del 24–25/10 que describen ambos repositorios como *privados* reflejan un dato obsoleto/no confirmado; la API actual informa *públicos*. La visibilidad debe comprobarse otra vez justo antes de habilitar controles y no debe alterarse para resolver revisores. No inferir que `public` significa que no hay secretos o expedientes: auditar separadamente si existe información sensible rastreable en Git.

**Inventario AWS UAT real:** último workflow inventario verificado [#40145562486](https://github.com/VaftecPeru/Hipocratiq_backend/actions/runs/40145562486): `Safety guards` SUCCESS; **job AWS real SKIPPED**, sin certificación de EC2/VPC real. Los checks y el éxito offline no constituyen un inventario.

**UAT clínica:** matriz Google Sheets `UAT CLINICA PRODUCCION`: 36 casos, **0 Aprobado**, 20 `Parcial CI`, 9 `Bloqueado`, 7 `Pendiente`.  
[Seguimiento P0 #92](https://github.com/VaftecPeru/Hipocratiq_backend/issues/92): abierto, **no cerrar por conveniencia**.

## 2. Configuración obligatoria de GitHub (propietario: administrador GitHub)

En **ambos** repositorios: `Settings > Branches > Add branch protection rule`, o ruleset de cumplimiento equivalente con permisos efectivos, uno para `develop` y otro para `main`.

- Requerir PR, mínimo **1 aprobación de otra persona**, descartar revisiones después de nuevos commits y exigir conversaciones resueltas.
- Requerir **checks actualizados** con `strict=true`; impedir push forzado, eliminación y bypass administrativo; verificar que no se puedan eludir revisiones.
- Configurar **nombres EXACTOS de checks** que existen (verificar contra los workflows antes de guardar):

| Repositorio/rama | Status checks obligatorios |
|---|---|
| Backend `develop` | `lint`; `Actual Next.js to Laravel with PostgreSQL17 Redis8 (no PHI)` |
| Backend `main` | Los anteriores; `Release evidence / main P0 NO-GO (read-only)` |
| Frontend `develop` | `web`; `Frontend CI` |
| Frontend `main` | Los anteriores; `Frontend E2E role routing` |

No exigir como obligatorio un job que aparezca `SKIPPED` bajo el evento correspondiente (p. ej. iOS no ejecutado). Antes de añadir checks, inspeccionar los nombres reales en las ejecuciones actuales. La protección es un requisito técnico y administrativo; un YAML correcto **no activa** las reglas por sí solo.

**Verificación independiente (solo GET):** el script existente `scripts/uat_github_admin_governance_readonly.py --live` requiere un token administrativo de **lectura** usado únicamente en una máquina controlada. El conector GitHub puede devolver 403 para `/protection`; un 403 no prueba cumplimiento.

## 3. Environment de GitHub y rol OIDC AWS (propietarios: administradores GitHub + AWS)

1. Backend `Settings > Environments > hipocratiq-uat-readonly`: habilitar **required reviewers** independientes, `prevent self-review` y política **custom branch** limitada únicamente a `develop`. Validar que la protección de Environment sea efectiva según el plan.
2. El rol IAM propuesto está en `infra/aws/uat-readonly-oidc-role.json`. Comprobar proveedor `token.actions.githubusercontent.com` y `aud=sts.amazonaws.com`; trust exacto: `repo:VaftecPeru/Hipocratiq_backend:environment:hipocratiq-uat-readonly`.
3. **Importante:** el `sub` del token OIDC con Environment **no contiene la rama**. Sin required reviewers y restricción de `develop`, el rol podría ser asumido desde otro workflow que utilice ese Environment. No habilitar IAM hasta verificar controles efectivos.
4. Revisar/aprobar el change set CloudFormation de solo lectura `HIPOCRATIQ-UAT-ReadOnly`. **No ejecutar aquí:** la creación del rol y cualquier modificación IAM requieren aprobación operativa separada del administrador AWS.
5. Corroborar independientemente AWS Account ID, región, EC2 UAT, EC2 producción, VPC UAT y VPC producción; no copiar ID de ejemplos ni publicar IPs, credenciales o metadatos sensibles en issues.

Variables GitHub Actions del Backend (solo valores verificados por admin):  
`HIPOCRATIQ_UAT_AWS_ACCOUNT_ID`, `HIPOCRATIQ_UAT_AWS_REGION`, `HIPOCRATIQ_UAT_INSTANCE_ID`, `HIPOCRATIQ_PROD_INSTANCE_ID`, `HIPOCRATIQ_UAT_VPC_ID`, `HIPOCRATIQ_PROD_VPC_ID`, `HIPOCRATIQ_UAT_READONLY_ROLE_ARN`.  
`HIPOCRATIQ_AWS_UAT_INVENTORY_ENABLED` debe permanecer deshabilitada hasta la ventana aprobada; `HIPOCRATIQ_AWS_UAT_RUN_ON_DEVELOP_PUSH=false` por defecto.

### Dependencia de GitHub Actions que hay que resolver antes de ejecutar

`uat-aws-readonly-inventory.yml` existe en `develop`; no se ha confirmado su presencia en la rama predeterminada `main`. GitHub normalmente exige que el archivo de `workflow_dispatch` exista en `main` para ofrecer la ejecución manual. **No utilizar un merge de toda la aplicación a main como atajo para habilitar el botón.** El administrador/release manager debe definir y aprobar un mecanismo seguro para disponer del workflow manual (por ejemplo, introducir **solo** el workflow auditado en `main` mediante cambio controlado independiente, una vez establecida su protección), o un mecanismo de disparo alternativo revisado por Seguridad. No simular evidencia con el job offline.

Tras autorizar el mecanismo, exigir **job AWS real `Approved AWS UAT instance metadata ONLY` = SUCCESS**, con artifact `hipocratiq-uat-aws-readonly-metadata` vigente y no vacío, y comprobar su contenido saneado. La mera existencia del artifact no sustituye la inspección humana del aislamiento EC2/VPC/EBS/SSM.

## 4. Gate clínico UAT (propietarios: QA, médico responsable y cliente)

No tocar producción. Preparar infraestructura UAT **separada** y con datos sintéticos:

1. Next.js + Laravel + PostgreSQL 17 + Redis accesibles mediante HTTPS UAT, endpoints `health/live` y `health/ready`, CORS/CSRF/Sanctum y logout.
2. OTP SMS México **+52** real con proveedor autorizado, 6 dígitos / 5 min / 5 intentos; probar reenvío, caducidad, bloqueo y fallos sin guardar OTP en logs.
3. Dos clínicas sintéticas A/B: probar paciente, médico, recepción, admin, FHIR/IDOR, membresía suspendida, consentimientos, auditoría y **consulta firmada inmutable**.
4. Backup cifrado UAT con checksum, restauración real en **segunda base/instancia aislada**, RPO/RTO, rollback de código y migraciones.
5. Flutter Android conectado al Backend UAT real, prueba en dispositivo y firma de release verificada; las builds debug que apuntan a `.invalid` no son publicables. iOS se cierra según alcance contractual.
6. Completar **36/36 escenarios reales**, resolver todos los P0/P1, adjuntar evidencias sin PHI y obtener acta de aceptación del cliente.

Solo con esas pruebas y las reglas GitHub **efectivamente verificadas**, cerrar el issue P0 #92, retirar DRAFT de PR #90 y Frontend #80, congelar SHAs exactos y repetir CI y validaciones release. Cada **merge a main** y **despliegue AWS** requieren autorizaciones operativas independientes.

## 5. Orden de cierre y STOP

1. Admin GitHub: protección `develop` y `main` → evidencia real.
2. Admin GitHub: Environment protegido → evidencia de rama única y revisores.
3. Admin AWS: validar/iniciar IAM OIDC read-only bajo cambio aprobado → evidencia STS/rol y separación.
4. Auditoría AWS real de solo lectura → job SUCCESS, artifact inspeccionado.
5. UAT HTTPS clínica y recuperación reales → 36/36 y acta.
6. PRs de release con SHAs recongelados, reglas y checks verdes → solicitar decisión GO / NO-GO.
7. Solo tras GO + autorizaciones explícitas → merge main y publicación vigilada con rollback preparado.

**STOP** ante ausencia de IDs corroborados, errores de permisos GitHub, Environment no protegido, AWS inventory `SKIPPED`, cualquier P0/P1 clínico, rollback no probado, pérdida de trazabilidad, o desalineación de SHAs. Ningún CI sintético debe marcar por sí solo la UAT real como aprobada.
