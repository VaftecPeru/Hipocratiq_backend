# HIPOCRATIQ — Desbloqueo verificable de UAT y main (25/10/2026)

**Estado actual: NO-GO clínico y operativo.** Esta guía prepara exclusivamente acciones de administración; este PR NO realiza cambios en GitHub Settings, AWS, base de datos o producción.

## Evidencia base (verificar de nuevo justo antes de cada acción)

- Backend develop: \`3180f2ca2d986844ae011c0cb2c40260f8d5272f\`; candidato a main: [PR #90](https://github.com/VaftecPeru/Hipocratiq_backend/pull/90), DRAFT.
- Frontend develop: \`fea9867729069216112f41d86f0d163588e7afb5\`; candidato a main: [PR #80](https://github.com/VaftecPeru/Hipocratiq_Frontend/pull/80), DRAFT.
- Backend main: \`c99c6e9391ea44986997c7a49c311b4177e6f731\`, protected=false.
- Frontend main: \`235bb34225e51f8e93b8aa134192d7e8971e1194\`, protected=false.
- P0 clínico: [issue #92](https://github.com/VaftecPeru/Hipocratiq_backend/issues/92) abierto.
- Gate de evidencia en PR #90: [#40160807547](https://github.com/VaftecPeru/Hipocratiq_backend/actions/runs/40160807547), FAIL (NO-GO esperado).
- CI Backend/Frontend y tests integrados **sintéticos** verdes NO equivalen a UAT real. Matriz clínica: 0/36 aprobados en UAT real.

## 1. Administrador GitHub: reglas efectivas de protección

En **ambos** repositorios, Settings > Branches > Add branch protection rule (o ruleset equivalente soportado por el plan). Configurar reglas para \`develop\` y \`main\`. No bastan los archivos YAML, ni marcar una variable \`protected=true\`.

1. Exigir Pull Request y al menos una aprobación de un revisor independiente.
2. Descartar aprobaciones al recibir commits nuevos y bloquear aprobación propia.
3. Exigir que las conversaciones estén resueltas y los checks estén actualizados a la última base.
4. No permitir force-push ni eliminación. Restringir bypass de administradores.
5. Añadir *exactamente los jobs de Actions existentes* como status checks obligatorios (confirmados con la API de GitHub):
   - Backend develop: \`lint\` y \`Actual Next.js to Laravel with PostgreSQL17 Redis8 (no PHI)\`.
   - Backend main: los dos anteriores y \`Release evidence / main P0 NO-GO (read-only)\`.
   - Frontend develop: \`web\` y \`Frontend CI\`.
   - Frontend main: \`web\`, \`Frontend CI\` y \`Frontend E2E role routing\`.
6. Verificar reglas con un token de lectura de administración y el script **existente** \`python3 scripts/uat_github_admin_governance_readonly.py --live\`. Nunca mostrar token o respuesta de protección en logs públicos.

**Limitación relevante:** el repositorio Backend es privado. Si el plan de GitHub no permite reglas/revisores requeridos o Environment protection, no continuar con OIDC ni release: resolver primero permisos/plan. La integración GitHub usada en este proyecto obtiene 403 al leer Branch Protection; ese 403 NO prueba que la regla exista.

## 2. Administrador GitHub: Environment read-only OIDC

En Backend > Settings > Environments crear o revisar \`hipocratiq-uat-readonly\`:

- Required reviewers independientes y prevent self-review habilitados.
- Deployment branches **custom**, con \`develop\` como única rama permitida (no comodín).
- Las reglas deben ser efectivas en el plan/repositorio usado. Comprobar por la API admin read-only, no asumir que el campo \`environment:\` del YAML asegura nada.
- El token OIDC GitHub puede usar \`sub=repo:VaftecPeru/Hipocratiq_backend:environment:hipocratiq-uat-readonly\`. Ese **sub no contiene la rama**: la restricción depende de la protección del Environment y del control de \`develop\`.

El script \`scripts/uat_github_admin_governance_readonly.py\` inspecciona protección de ambas ramas en ambos repos, required checks, reviewers y restricciones del Environment. Invocación \`--live\` únicamente por administrador autorizado en entorno controlado (GET read-only).

## 3. Administrador AWS: instalar IAM OIDC read-only

Solo tras completar el punto 2:

- Identificar con evidencia **real** el AWS Account ID, región, EC2/VPC de UAT y producción, EBS y SSM. No copiar datos de ejemplo.
- Revisar el stack IaC ya versionado: \`infra/aws/uat-readonly-oidc-role.json\`.
- Comprobar el proveedor OIDC de IAM \`token.actions.githubusercontent.com\` y su audiencia \`sts.amazonaws.com\` en la cuenta **aprobada**.
- Solicitar revisión de permisos IAM y aprobación del change set CloudFormation antes de crear \`HIPOCRATIQ-UAT-ReadOnly\`. El rol solo contiene permisos Describe EC2/SSM. No usar claves AWS permanentes, SSM RunCommand, Secrets Manager GetSecretValue ni permisos de modificación.
- El rol AWS **no** sustituye validación del Environment; una identidad OIDC no limita por sí sola el workflow a la rama develop.

No implementar CloudFormation ni iniciar sesiones AWS desde este PR. Toda instalación y cambios IAM requieren acción y autorización independientes del administrador.

## 4. Variables de Actions (valores comprobados, no inventados)

Configurar en Backend, **solo después de verificar el inventario real**:

- \`HIPOCRATIQ_UAT_AWS_ACCOUNT_ID\`, \`HIPOCRATIQ_UAT_AWS_REGION\`.
- \`HIPOCRATIQ_UAT_INSTANCE_ID\`, \`HIPOCRATIQ_PROD_INSTANCE_ID\`.
- \`HIPOCRATIQ_UAT_VPC_ID\`, \`HIPOCRATIQ_PROD_VPC_ID\`.
- \`HIPOCRATIQ_UAT_READONLY_ROLE_ARN\`.
- \`HIPOCRATIQ_AWS_UAT_INVENTORY_ENABLED=true\` solo al aprobar la ventana; \`HIPOCRATIQ_AWS_UAT_RUN_ON_DEVELOP_PUSH=false\` para evitar inventarios no solicitados.

Ejecutar manualmente Actions > **UAT AWS Inventory (read-only, opt-in)**, desde \`develop\`, con \`READ_ONLY_UAT_INVENTORY\`, y aprobar el Environment con revisores. Gate correcto:

- El job **Approved AWS UAT instance metadata ONLY** termina \`SUCCESS\`, no \`SKIPPED\`.
- El artifact \`hipocratiq-uat-aws-readonly-metadata\` no está vacío ni caducado y muestra únicamente metadatos sin PHI, secretos, IP sensible o tags privadas.
- Cuenta, región, VPC, EC2, EBS cifrado, SSM y segmentación UAT/producción coinciden con el inventario autorizado. No solo confiar en un status verde.
- Un error 403, un job skipped o datos no coincidentes son **NO-GO**.

## 5. UAT clínica y autorización de release

Después del inventario AWS y del despliegue **aislado** de UAT (con autorización propia):

- HTTPS de Next.js y Laravel con PostgreSQL17/Redis8, sin reutilizar base, credenciales ni datos clínicos de producción.
- OTP SMS **real +52** con remitente/cuenta autorizados, 6 dígitos, 5 minutos, máximo 5 intentos, revocación/CSRF/Sanctum; no almacenar códigos en logs.
- Clínica A/B, médicos/pacientes/recepción, FHIR-IDOR, consentimiento, auditoría y consulta firmada inmutable.
- Backup cifrado y restaurado en instancia aislada, prueba de rollback y RPO/RTO documentados.
- Android conectado a HTTPS UAT y validado en dispositivo físico, build release firmado e integridad SHA-256; iOS según alcance contractual.
- Matriz [UAT CLINICA PRODUCCION](https://docs.google.com/spreadsheets/d/1SwuLEHgvPyDVfQC3xic-Fg4d1NzUY4vrl0WCEfEBhls/edit) **36/36 aprobada de verdad**, cero P0/P1 abiertos y acta cliente firmada.

Solo después, cerrar el issue #92 con evidencias reales, quitar DRAFT de PR #90/#80, recongelar SHA exactos, repetir CI en ambos candidatos y obtener **autorización diferenciada para merge main** y **otra para despliegue AWS**.

**Nunca cerrar el issue, crear metadata ficticia, omitir checks ni fusionar main para forzar el estado verde.**
