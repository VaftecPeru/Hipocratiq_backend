# HIPOCRATIQ — Diagnóstico AWS UAT vía GitHub Actions (24/10/2026)

**Estado actual: NO-GO clínico y productivo.** Esta guía no autoriza modificaciones de AWS, producción o ramas `main`.

## Evidencia obtenida

- Backend PR #93 fusionado a `develop`, SHA `89b6f3a2cc259501ab23bb006d9acea8f4baed10`, tras 3 CI de PR en verde.
- Backend PR #90 y Frontend PR #80: siguen `DRAFT` a `main`, no fusionados.
- AWS Inventory #40122869866: `offline-safety=SUCCESS`, `aws-uat-inventory=SKIPPED`, **0 artifacts**; no prueba AWS real.
- Backend #92 (seguimiento P0): abierto.
- Protección `main` Backend y Frontend: `protected=false`.
- CI Backend posterior al merge del PR #93: ejecución #40143292081; no asumir SUCCESS antes de cierre.

## A. Diagnóstico automático sin AWS

El nuevo workflow `.github/workflows/uat-aws-configuration-diagnostic.yml` se ejecuta en cada PR a `develop` que lo cambie. Lee variables GitHub Actions en memoria, **sin publicar sus valores** y sin conectarse a AWS.

Resultados posibles:
- `BLOCKED_NO_AWS_DIAGNOSTIC`: faltan variables, el opt-in no está activo o hay contradicciones en los identificadores declarados.
- `CONFIG_SYNTAX_PASS_NOT_AWS_VERIFIED`: sintaxis de variables y activación correcta; **todavía no confirma IAM, Environment, IDs reales ni AWS.**

Guarda un artifact `hipocratiq-aws-uat-config-offline-only` (7 días) con booleanos de presencia/formato, códigos de bloqueo y requisitos manuales. **Nunca** denominarlo inventario AWS.

## B. Configuración previa por un administrador autorizado

1. Confirmar en AWS Console que el proveedor IAM OIDC `token.actions.githubusercontent.com` existe en la cuenta correcta, con audiencia `sts.amazonaws.com`.
2. Revisar plantilla `infra/aws/uat-readonly-oidc-role.json` que propone **solo** crear `HIPOCRATIQ-UAT-ReadOnly` con permisos EC2/SSM Describe. Su trust `sub` limita a `repo:VaftecPeru/Hipocratiq_backend:environment:hipocratiq-uat-readonly`. **El administrador necesita autorización AWS separada para crear o aplicar esa plantilla.**
3. En GitHub Backend → Settings → Environments, configurar `hipocratiq-uat-readonly`, con revisores obligatorios, rama permitida exclusivamente `develop` y control de acceso de mínimo privilegio.
4. Verificar de manera **independiente** los identificadores reales de cuenta, región, instancia y VPC UAT, y sus correspondientes exclusiones de producción. Evitar que `prod` y `uat` compartan recursos o cuentas operativas que impidan aislamiento.
5. En GitHub Actions → Variables, establecer solamente valores verificados. No introducir AWS access keys, claves privadas, credenciales ni OTP.

Variables utilizadas por el workflow existente:

| Variable de Actions | Requisito |
|---|---|
| `HIPOCRATIQ_AWS_UAT_INVENTORY_ENABLED` | `true` solo tras las aprobaciones |
| `HIPOCRATIQ_UAT_AWS_ACCOUNT_ID` | Cuenta exacta verificada |
| `HIPOCRATIQ_UAT_AWS_REGION` | Región real UAT |
| `HIPOCRATIQ_UAT_INSTANCE_ID` | EC2 UAT aprobada |
| `HIPOCRATIQ_PROD_INSTANCE_ID` | EC2 producción, solo para exclusión |
| `HIPOCRATIQ_UAT_VPC_ID` | VPC UAT aprobada |
| `HIPOCRATIQ_PROD_VPC_ID` | VPC producción, solo para exclusión |
| `HIPOCRATIQ_UAT_READONLY_ROLE_ARN` | ARN de rol UAT dedicado |
| `HIPOCRATIQ_AWS_UAT_RUN_ON_DEVELOP_PUSH` | Mantener `false` para evitar ejecuciones AWS automáticas |

No divulgar aquí los valores. Una validación correcta de formato **no demuestra** que pertenezcan a un entorno UAT segregado.

## C. Ejecutar la comprobación AWS de solo lectura

**Después** de completar A y B y obtener aprobación explícita de ejecución UAT:

1. GitHub Backend → Actions → **UAT AWS Inventory (read-only, opt-in)**.
2. Pulsar **Run workflow**, seleccionar rama **develop**, introducir `READ_ONLY_UAT_INVENTORY`.
3. Autorizar el Environment `hipocratiq-uat-readonly` con sus revisores.
4. Exigir que el job **Approved AWS UAT instance metadata ONLY** alcance `COMPLETED/SUCCESS`, no `SKIPPED`.
5. Revisar el artifact `hipocratiq-uat-aws-readonly-metadata`; comprobar `INVENTORY_PASS_NOT_RELEASE_APPROVAL` y ausencia de blockers, sin incluir PHI ni secretos. Guardar run ID y fecha.
6. Si hay errores IAM/OIDC, EC2, EBS o SG, mantener **NO-GO** y corregir solamente tras evaluación de seguridad.

El script AWS solo ejecuta `sts:GetCallerIdentity`, `ec2:DescribeInstances`, `ec2:DescribeInstanceStatus`, `ec2:DescribeVolumes`, `ec2:DescribeSecurityGroupRules` y `ssm:DescribeInstanceInformation`. Nunca permite `SendCommand`, `StartSession`, cambios de EC2, migraciones ni SMS.

## D. Bloqueos independientes antes de producción

- HTTPS UAT real entre Next.js, Laravel, PostgreSQL 17 y Redis.
- OTP mexicano real +52 aprobado y controles de expiración/errores.
- 36 escenarios clínicos de aceptación, cero P0/P1; aislamiento de clínicas A/B y auditoría.
- Backup cifrado UAT y restauración probada en una instancia aislada, rollback y monitorización.
- Protección `main` y required checks en ambos repositorios; issue #92 cerrado solo con evidencias.
- Acta de aceptación cliente, autorización explícita de merge a `main` y de despliegue AWS.
- Frontend PR #80 y Backend PR #90 deben permanecer DRAFT hasta superar los gates.

**Advertencia:** el inventario de solo lectura verifica metadatos EC2/VPC, no docker, TLS, datos clínicos ni restauración. Nunca cambiar un PR release a READY solo para satisfacer el guard.
