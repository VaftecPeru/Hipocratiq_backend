# HIPOCRATIQ — Inventario AWS producción #113 (solo lectura)

**Autorizado 29/10/2026:** inventario metadata read-only. **No** se autorizaron snapshots nuevos, restauración, cambios de cifrado, ejecución de despliegues ni uso del rol UAT en producción.

## Precondiciones antes de consultar AWS
1. Confirmar un rol **PROD inventory-readonly** distinto de `HIPOCRATIQ-UAT-ReadOnly`, con confianza OIDC limitada al repositorio/ref y Environment con revisor humano. No reutilizar rol UAT.
2. Verificar vía `sts get-caller-identity` cuenta y rol esperados; abortar si difieren. Región e instancia exacta requieren validación independiente del operador.
3. No escribir nombres de snapshots, cuentas, volúmenes, KMS ARNs, IP, etiquetas, credenciales, logs con PHI ni payloads de backup en repositorios **públicos**. Depositar informe completo en canal privado.
4. La inspección de metadatos es **no mutante**. No invocar `create-snapshot`, `start-restore-job`, `modify-volume`, `delete-*` ni SSM `send-command`.

## Procedimiento de inventario para operador autorizado
En una sesión ya autenticada con rol de **producción de solo lectura**, verificando primero cuenta y región (NO ejecutar desde workflow UAT):

```bash
set -Eeuo pipefail
aws sts get-caller-identity --query '{Account:Account,Arn:Arn}' --output json
aws ec2 describe-instances --instance-ids "$PROD_INSTANCE_ID" \
  --query 'Reservations[].Instances[].{InstanceId:InstanceId,State:State.Name,Volumes:BlockDeviceMappings[].Ebs.VolumeId}' --output json
aws ec2 describe-volumes --filters "Name=attachment.instance-id,Values=$PROD_INSTANCE_ID" \
  --query 'Volumes[].{Encrypted:Encrypted,State:State,Size:Size,VolumeId:VolumeId,KmsKeyId:KmsKeyId}' --output json
aws ec2 describe-snapshots --owner-ids self \
  --filters "Name=status,Values=completed" \
  --query 'Snapshots[].{SnapshotId:SnapshotId,VolumeId:VolumeId,StartTime:StartTime,Encrypted:Encrypted,State:State}' --output json
aws backup list-backup-vaults --query 'BackupVaultList[].{VaultName:BackupVaultName,NumberOfRecoveryPoints:NumberOfRecoveryPoints}' --output json
```

**Advertencia:** Snapshot existente/estado completed ≠ evidencia de restauración utilizable; además, un snapshot EBS no demuestra backup coherente de PostgreSQL ni archivos clínicos. Comprobar pertenencia a los volúmenes reales, política de retención, cifrado KMS y prueba de recuperación aislada posteriormente autorizada.

## Registro de evidencias (privado)
- Fecha UTC, responsable, SHA/ID de cambio, cuenta/región/rol verificados: PENDIENTE
- Volúmenes de instancia y cifrado efectivo: PENDIENTE
- Snapshots coherentes, antigüedad y retención: PENDIENTE
- Backup PostgreSQL, archivos adjuntos y consistencia: PENDIENTE
- Evidencia de restauración aislada: **NO EJECUTADA / NO AUTORIZADA**
- Firma operativa y riesgo residual: PENDIENTE

**Gate:** hasta obtener autenticidad de inventario + restauración segura autorizada, **NO-GO**. Este documento no ejecuta ninguna llamada AWS.
