# HIPOCRATIQ — GitHub Actions → main → AWS EC2 (release controlado)

Estado inicial 03/11/2026: **NO-GO**. Esta solución prepara la conexión de despliegue, no cambia AWS ni pone en producción código. No activa despliegue automático al fusionar a main.

## Qué ejecuta GitHub Actions

Workflow: `.github/workflows/controlled-main-aws-release.yml`, situado en Backend como **coordinador de los dos repositorios**.

- En pull requests a develop/main: valida scripts, plantilla IAM y pruebas de seguridad **sin acceder a AWS**.
- En push a Backend `main`: inspecciona mediante GitHub API los SHA de ambas ramas main, confirma protección y estado de los issues Backend **#92, #102, #111 y #113**. Si falta una evidencia, responde `NO_GO` y **no despliega**.
- Solo en ejecución **manual** `workflow_dispatch` sobre Backend main, con ambos SHA exactos, actas de aprobación y flags de habilitación, solicita aprobación independiente del Environment `hipocratiq-production`. Repite el gate después de la aprobación humana, **antes** de solicitar credenciales AWS.
- Con OIDC temporal del rol `HIPOCRATIQ-PROD-Deploy` (distinto de `HIPOCRATIQ-PROD-ReadOnly`), verifica cuenta, región, instancia, VPC y SSM Online. Ejecuta comandos remotos vía AWS SSM en **una EC2 exacta**. Sin SSH, claves AWS estáticas, PCs remotas ni acceso a datos clínicos desde Actions.
- En EC2: despliega **Backend primero** y Frontend después con sus scripts versionados desde los SHA de `main`, respaldos previos, healthchecks y rollback de código ya definidos. Ante fallo de Backend, Frontend no inicia. Ante fallo de Frontend, se detiene y requiere intervención: migraciones Backend **NO** se revierten automáticamente.
- Al terminar comprueba HTTPS público de Frontend y API sin cookies, PHI, OTP, logins ni consultas de pacientes. **HTTP 200 no demuestra SHA Docker**; obtener evidencia de contenedores requiere un procedimiento independiente autorizado.

## Dependencias administrativas (no ejecutadas desde el PR)

1. Resolver y documentar cierre de los P0 Backend **#92 clínica, #102 seguridad privada, #111 UAT/alternativa aprobada y #113 restauración**. El gate se niega a publicar mientras cualquiera siga abierto. El cierre de un issue por sí solo NO sustituye un acta firmada y revisión de evidencias.
2. Validar restauración en entorno aislado, cifrado de backup, compatibilidad de migraciones y plan probado de rollback, sin datos clínicos reales.
3. Aprobar **por separado** plantilla `infra/aws/prod-deploy-oidc-role.json`. Crear en AWS un rol `HIPOCRATIQ-PROD-Deploy` con `ProductionInstanceId` **verificado**, OIDC subject exacto `repo:VaftecPeru/Hipocratiq_backend:environment:hipocratiq-production` y audiencia `sts.amazonaws.com`. Policy: `ec2:DescribeInstances`, `ssm:DescribeInstanceInformation`, `ssm:SendCommand` para únicamente la EC2 verificada + documento `AWS-RunShellScript`, `ssm:GetCommandInvocation`. **Nunca** conceder `iam:PassRole`, `ssm:StartSession`, KMS decrypt ni privilegios genéricos.
4. GitHub Backend **Settings → Environments → hipocratiq-production**: requerir revisores diferentes de quien promueve, restricción exclusiva a rama **main protegida**, y sin self-review/bypass. Confirmar condiciones antes de activar.
5. Configurar variables verificadas de GitHub Actions Environment:
   - `HIPOCRATIQ_PROD_AWS_ACCOUNT_ID`, `HIPOCRATIQ_PROD_AWS_REGION`, `HIPOCRATIQ_PROD_INSTANCE_ID`, `HIPOCRATIQ_PROD_VPC_ID`, `HIPOCRATIQ_PROD_DEPLOY_ROLE_ARN`.
   - `HIPOCRATIQ_BACKUP_ENCRYPTION_ADMIN_VERIFIED=true` **solo con evidencia real y aprobación**.
   - `HIPOCRATIQ_PROD_DEPLOY_ENABLED=true` como flag administrativo de nivel repositorio visible al evaluar `jobs.if`, **solo tras aprobar toda la configuración**. Por defecto no existe y bloquea el job.
6. Validar que el host AWS cuenta con agente SSM Online, acceso a git remotes autenticado, `/srv/hipocratiq/backend` y `/srv/hipocratiq/frontend` en main, Docker Compose correcto, backups seguros y permisos para ejecución de scripts. Inspeccionar versiones reales de contenedores ANTES de publicar. SSM command output se omite deliberadamente de los logs GitHub Actions.
7. Fusionar Frontend y Backend a main mediante PRs aprobados y CI/gates sobre SHA exactos; no adelantar merges a main para probar workflows con P0 abiertos.
8. Usar Actions → **Controlled HIPOCRATIQ main to AWS** → Run workflow en rama `main`, ingresar acknowledgement exacto `APPROVED_HIPOCRATIQ_CONTROLLED_PRODUCTION`, SHA completo Frontend main, ID de cambio y referencias de actas clínica, seguridad, restore y cifrado. Confirmar aprobación Environment y verificar el run. **No compartir valores de secretos, PHI ni respaldos** en inputs.

## Limitaciones conocidas

- **No despliegue automático** en push a main: para sistema clínico el despliegue es **manual y autorizado**.
- El rol propuesto no existe por el mero hecho de estar versionado; crear AWS IAM es responsabilidad de administrador AWS.
- La autorización `HIPOCRATIQ-PROD-ReadOnly` es distinta y NO debe ampliar permisos para despliegue.
- Desplegar ambos componentes en secuencia **no es transacción atómica**. Una migración de base de datos puede impedir rollback de versión. Exigir migraciones compatibles y plan de contingencia.
- Scripts de despliegue existentes deben probarse en réplica de AWS con Docker Compose equivalente antes de la primera publicación.
- Si CI de main no habilita el runner, el flujo de deploy no debe ejecutarse. No considerar la coincidencia de SHAs de ramas evidencia de versiones desplegadas.
- El resultado `SUCCESS` de SSM confirma ejecución de script; **no** garantiza integridad clínica, aprobación del cliente ni paridad entre digest de Docker y SHA Git sin inspección del runtime.

**Estado release: NO-GO hasta cierre documentado de los P0 y autorización humana.**
