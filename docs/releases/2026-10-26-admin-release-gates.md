# HIPOCRATIQ — Hoja de ejecución P0 antes de publicar (26/10/2026)

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
