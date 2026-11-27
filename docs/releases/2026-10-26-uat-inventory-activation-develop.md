# HIPOCRATIQ — Activación real del inventario AWS UAT sin cambiar main

**Fecha:** 26/10/2026. **Estado:** NO-GO hasta evidencia AWS/seguridad/36 UAT y aprobación clínica.

## Bloqueo técnico resuelto por este PR

El workflow de inventario existía únicamente en develop y su `workflow_dispatch` puede no aparecer hasta existir en la rama predeterminada main. Además, el trigger de push tenía `paths`, de modo que configurar el Environment, OIDC y las variables no era suficiente para que el inventario se ejecutase después de un cambio ordinario.

Este PR elimina **solo los filtros de rutas del evento `push` a develop**. La ejecución que toca AWS mantiene TODOS sus requisitos independientes:

- `GITHUB_REF=refs/heads/develop`
- `HIPOCRATIQ_AWS_UAT_INVENTORY_ENABLED=true`
- Para push: `HIPOCRATIQ_AWS_UAT_RUN_ON_DEVELOP_PUSH=true`, con reconocimiento ya previsto
- Environment `hipocratiq-uat-readonly`, limitado a **develop** con reviewer independiente y sin self-review
- `uat_aws_inventory_readonly.py --preflight` valida cuenta, región, IDs, rol y consentimiento
- `uat_github_protected_develop_preflight.py` exige rama protegida y SHA exacto **ANTES** de OIDC
- OIDC temporal, `allowed-account-ids`, permisos solo Describe y salida sanitizada; sin comandos ni acceso a datos de pacientes

**Por defecto** las variables de habilitación deben seguir en `false`, por lo que cada push solo ejecuta pruebas offline; el job AWS real queda **SKIPPED**, como corresponde.

## Trabajo administrativo necesario (no completado por código)

1. El propietario y Seguridad resuelven el P0 de repositorios **públicos** e historial histórico (#102) en canal privado; determinar si los valores encontrados eran operativos y rotar credenciales cuando corresponda. No publicar valores ni ejecutar cambio automático de visibilidad.
2. El administrador GitHub, en estación de confianza (no Actions), ejecuta y verifica `scripts/configure_github_release_governance.py` desde una versión auditada para proteger main/develop de los dos repositorios y configurar Environment `hipocratiq-uat-readonly`. Revisar que los **nombres exactos** de required checks aparezcan en GitHub. Debe existir un revisor distinto de quien inicia el trabajo.
3. El administrador AWS verifica cuenta, región, IAM GitHub OIDC, EC2/VPC **de UAT y de producción por separado**, las etiquetas/volúmenes, y revisa `infra/aws/uat-readonly-oidc-role.json` y su change set. No ejecutar cambios IAM sin autorización AWS separada.
4. Tras configurar el rol, registrar las variables `HIPOCRATIQ_UAT_AWS_ACCOUNT_ID`, `HIPOCRATIQ_UAT_AWS_REGION`, `HIPOCRATIQ_UAT_INSTANCE_ID`, `HIPOCRATIQ_PROD_INSTANCE_ID`, `HIPOCRATIQ_UAT_VPC_ID`, `HIPOCRATIQ_PROD_VPC_ID`, `HIPOCRATIQ_UAT_READONLY_ROLE_ARN`. Las variables son identificadores, **no credenciales**.
5. Con protección y Environment ya verificados, activar `HIPOCRATIQ_AWS_UAT_INVENTORY_ENABLED=true`, `HIPOCRATIQ_AWS_UAT_RUN_ON_DEVELOP_PUSH=true` y el reconocimiento `HIPOCRATIQ_AWS_UAT_PUSH_ACKNOWLEDGEMENT=READ_ONLY_UAT_INVENTORY` solamente para la ventana **aprobada**. El siguiente push **legítimo y revisado** a develop disparará la comprobación offline y, después del reviewer, la consulta de metadatos AWS. No se requiere hacer merge a main para obtener el workflow.
6. Tras obtener resultado AWS `COMPLETED/SUCCESS` y artefacto sanitizado, desactivar las dos flags, revisar el artefacto y registrar evidencia en bitácora. Un job `SKIPPED`, un PASS solo offline o variables declaradas **no acreditan** el aislamiento UAT.

## Falta antes de producción

HTTPS Web/API UAT independientes, OTP SMS México +52 con proveedor autorizado, pruebas por rol y dos clínicas, 36/36 UAT reales, respaldo y restauración aislados, rollback, APK/AAB firmado y aceptación del cliente. Backend PR #90 y Frontend PR #80 siguen **DRAFT**. El release gate intencional sigue NO-GO y NO debe desactivarse, ni cambiar `main` o AWS producción.

## Autoridad y alcance

Esta rama modifica workflow, test de activación y este documento; **no crea ni cambia rol IAM, Environment GitHub, protección de ramas, instancias AWS, expedientes ni datos reales**. Requiere CI y autorización de merge a develop. No realizar `main` merge o producción con este PR.
