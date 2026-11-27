# HIPOCRATIQ — P0 #113: inventario EBS/snapshots AWS (solo lectura)

**Estado: preparación CI. No es un ensayo de restauración ni certificación de respaldo.** No toca AWS hasta aprobación administrativa expresa. Se mantiene la decisión del responsable de posponer creación de snapshots/restores.

## Alcance autorizado y control de acceso

Basado en la autorización limitada de solo lectura registrada en [Backend #113](https://github.com/VaftecPeru/Hipocratiq_backend/issues/113), se agrega una etapa OPCIONAL al workflow `production-version-evidence-readonly.yml`.

El job continúa deshabilitado a menos que el administrador valide antes:
1. Environment `hipocratiq-prod-readonly`, revisores independientes y `develop` protegida.
2. Trust GitHub OIDC vinculado a ese Environment, cuenta, rol `HIPOCRATIQ-PROD-ReadOnly` y la instancia/VPC productivas verificadas, sin copiar IDs de AWS de esta guía como verdad.
3. Flags de habilitación existentes `HIPOCRATIQ_PROD_METADATA_ENABLED=true`, `HIPOCRATIQ_PROD_METADATA_ON_DEVELOP_PUSH=true`, `HIPOCRATIQ_PROD_METADATA_ACK=READ_ONLY_PROD_METADATA`.
4. Para este alcance extra: `HIPOCRATIQ_PROD_BACKUP_METADATA_ENABLED=true` y `HIPOCRATIQ_PROD_BACKUP_METADATA_ACK=READ_ONLY_EBS_SNAPSHOT_INVENTORY`.
5. Agregar **únicamente** las acciones IAM de lectura `ec2:DescribeVolumes` y `ec2:DescribeSnapshots` al rol ya revisado; conservar `ec2:DescribeInstances`, `ec2:DescribeInstanceStatus`, `ssm:DescribeInstanceInformation`, `sts:GetCallerIdentity` donde sean necesarios. No permitir comandos SSM, KMS decrypt, lecturas de objetos S3, lectura de BD ni acciones de escritura.

**Configuración:** el job AWS se ejecuta solo desde push a `develop` bajo la doble aprobación establecida en el workflow y tras revisar el commit. PRs ejecutan exclusivamente tests offline con AWS simulado. Ningún entorno externo se modifica al abrir el PR.

## Evidencia recolectada y límites

Se comprueba la cuenta y el rol, el ID exacto de EC2 y su VPC, y únicamente los volúmenes EBS **actualmente adjuntos** a esa instancia. Para cada volumen, se consulta su estado de cifrado y si tiene referencia KMS; se listan metadatos de snapshots cuyo propietario es la cuenta y cuyo `VolumeId` coincide con el volumen, con paginación y límites de seguridad.

Se registra solo un JSON de **conteos**: volúmenes adjuntos, volúmenes cifrados, referencias KMS presentes, snapshots completados, snapshots cifrados y cuántos volúmenes poseen un snapshot cifrado creado en los últimos 30 días. **Nunca** escribir identificadores de volúmenes/snapshots, ARN de KMS, descripciones, etiquetas, rutas, contenido ni PHI al artifact.

Si el resultado es `READ_ONLY_METADATA_COLLECTED_RECOVERY_UNVERIFIED`, significa solo **inventario legible**. La existencia de snapshots, incluso cifrados, NO acredita backup consistente de PostgreSQL, retención real, cifrado del respaldo lógico, restaurabilidad ni rollback. Volúmenes no adjuntos, AWS Backup, S3, archivos y snapshots eliminados no se evalúan.

## Para cerrar #113 (acción separada, no autorizada por este PR)

- Verificar política y retención efectiva de backups AWS, PostgreSQL y ficheros.
- Obtener aprobación explícita para recuperación de ensayo, con red aislada y base temporal sin PHI.
- Ejecutar restauración controlada y medir integridad, tiempo y rollback de código/esquema, sin modificar datos productivos.
- Registrar acta privada y aprobación responsable; nunca marcar 100% o cerrar P0 solo por un workflow que tenga SUCCESS.

## Seguridad del código

- Allowlist AWS solo permite STS y los `Describe` concretos; niega `StartSession`, `SendCommand`, `CreateSnapshot`, `ModifyVolume`, `DeleteSnapshot` y `StartRestoreJob`.
- Preflight OIDC y flags antes del acceso real; exige coincidencia de cuenta, VPC e instancia.
- Tests sintéticos de cuentas inesperadas, metadatos erróneos, archivos sin snapshots, cifrado y paginación.
- Si API o permisos fallan, la ejecución resulta `INCOMPLETE_NO_GO`; nunca desactivar el gate de release por esa falla.

**P0 #113 permanece OPEN.** El resto de los controles #92, #102 y #111 / AWS también permanecen pendientes.
