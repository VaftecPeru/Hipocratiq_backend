# HIPOCRATIQ — Habilitación administrativa GitHub + AWS OIDC (solo lectura)
Fecha: 03/11/2026. Este documento es un procedimiento de aprobación, **no evidencia de ejecución**.

## 1. GitHub: reparar protección Frontend develop

Estado consultado por la API de GitHub: Frontend `develop` **protected=false**; `main` protegida. Administrador del repositorio debe ir a `VaftecPeru/Hipocratiq_Frontend → Settings → Rules → Rulesets` y crear/ajustar un ruleset dirigido a `develop`:
- Requerir pull request, al menos un reviewer distinto del autor y resolución de conversaciones.
- Exigir checks reales obligatorios de CI, build y pruebas E2E sin inventar nombres ni marcar checks omitidos como verdes. Confirmar que se ven en el PR.
- Bloquear force pushes, eliminación de rama y bypass no autorizado.
- Comprobar efectividad sobre `develop` mediante GitHub API y repetir el gate del PR Frontend #80. No retirarlo de DRAFT antes de los P0 y las aprobaciones clínicas.

No hay acción administrativa de branch protection habilitada en el conector GitHub actual: **este PR no modifica Rulesets**.

## 2. AWS: revisar rol de metadatos de producción

Propuesta versionada: `infra/aws/prod-readonly-oidc-role.json`. Contiene exclusivamente un rol IAM con nombre `HIPOCRATIQ-PROD-ReadOnly`. AWS no ha sido modificado; este archivo **no se despliega automáticamente**.

El administrador AWS, previa aprobación del alcance, debe comprobar proveedor GitHub OIDC existente, cuenta objetivo, políticas IAM actuales y conflictos de nombres; después crear o adaptar el rol mediante procedimiento IAM autorizado (CloudFormation requiere `CAPABILITY_NAMED_IAM`, no ejecutar sin autorización de seguridad). El rol permite solamente:
- `ec2:DescribeInstances`, `ec2:DescribeInstanceStatus`;
- `ec2:DescribeVolumes`, `ec2:DescribeSnapshots`;
- `ssm:DescribeInstanceInformation`.

**No** se autorizan `ssm:SendCommand`, `ssm:StartSession`, acceso a PHI, `kms:Decrypt`, `secretsmanager:GetSecretValue`, acciones de mutación de EC2, ejecución de backups/restores ni despliegues.

La trust policy exige token OIDC con `aud=sts.amazonaws.com` y `sub=repo:VaftecPeru/Hipocratiq_backend:environment:hipocratiq-prod-readonly`. El subject de Environment **no fija la rama**; por tanto, es obligatorio restringir las ramas del Environment a `develop` protegido y exigir revisores sin bypass. El workflow vuelve a verificar SHA/branch protegida antes del intercambio de credenciales.

## 3. Configuración GitHub Actions

En repositorio Backend, `Settings → Environments`: configurar `hipocratiq-prod-readonly`, revisores y restricciones de `develop`. Usar variables de entorno para metadatos verificables (nunca credenciales AWS estáticas):
- `HIPOCRATIQ_PROD_AWS_ACCOUNT_ID`, `HIPOCRATIQ_PROD_AWS_REGION`, `HIPOCRATIQ_PROD_INSTANCE_ID`, `HIPOCRATIQ_PROD_VPC_ID`, `HIPOCRATIQ_PROD_READONLY_ROLE_ARN`.
- Para habilitar inventario general exclusivamente tras revisión: `HIPOCRATIQ_PROD_METADATA_ENABLED=true`, `HIPOCRATIQ_PROD_METADATA_ON_DEVELOP_PUSH=true`, `HIPOCRATIQ_PROD_METADATA_ACK=READ_ONLY_PROD_METADATA`. Estos flags deben ser visibles al evaluar `jobs.<job>.if` (nivel repo si fuese necesario); mantener el rol/IDs en Environment.
- Para agregar diagnóstico no destructivo de snapshots #113, aprobación separada de `HIPOCRATIQ_PROD_BACKUP_METADATA_ENABLED=true` y `HIPOCRATIQ_PROD_BACKUP_METADATA_ACK=READ_ONLY_EBS_SNAPSHOT_INVENTORY`. La decisión previa de diferir restore sigue vigente.

Workflow: `.github/workflows/production-version-evidence-readonly.yml`. Se activa con push revisado a `develop` que modifique archivos bajo `paths` (el mero cambio de variables no dispara un push). Ejecuta primero pruebas offline y preflight; luego requiere aprobación de Environment. Solo entonces asume rol temporal OIDC y realiza llamadas AWS de lectura. Configurar triggers adicionales únicamente en PR revisado, no desactivar controles.

## 4. Evidencias y criterios

- Guardar URL de run, SHA exacto, aprobador y artifact saneado de inventario real; no compartir IDs, ARN KMS o secretos fuera del canal autorizado.
- La lectura de EBS/snapshots **no verifica que PostgreSQL pueda restaurarse**. #113 permanece abierto hasta ensayo aislado autorizado; no ejecutar `backups:restore` en producción.
- #92 UAT clínica requiere 36/36 casos ejecutados y aprobación independiente, sin datos de pacientes reales.
- #102 seguridad requiere clasificación confidencial de historial/artifacts y rotación por dueños de servicio si aplica.
- #111 UAT separada requiere presupuesto y decisión del cliente, o alcance alternativo de web release aprobado formalmente; nunca crear infraestructura de pago de forma automática.

**NO-GO:** ningún CI/infra inventario autoriza merge a `main` ni despliegue AWS por sí solo.
