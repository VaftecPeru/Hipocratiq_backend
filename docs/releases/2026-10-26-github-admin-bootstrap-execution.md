# HIPOCRATIQ — Desbloqueo de GitHub Settings y AWS UAT

**26 de octubre de 2026. ESTADO: NO-GO.** Este procedimiento cambia solamente configuración GitHub cuando un administrador lo ejecuta explícitamente; no llama AWS, no realiza merge a main, no envía OTP ni accede a datos clínicos.

## Hechos verificados

- Backend y Frontend: GitHub API informa visibilidad **public** en ambos. Revisar seguridad del código, credenciales y posible información sensible. No cambiar visibilidad como atajo para obtener revisores.
- main y develop: protected=false en ambos repositorios. Rulesets: ninguno.
- El conector GitHub actual devuelve HTTP 403 al consultar Branch Protection y no expone APIs de administración. Desktop Commander VAFTEC está offline. No afirmar que Settings están configurados.
- GitHub Environment hipocratiq-uat-readonly y rol IAM AWS HIPOCRATIQ-UAT-ReadOnly no tienen evidencia operativa suficiente.
- Release Backend PR #90 y Frontend PR #80 siguen en DRAFT; issue clínico P0 #92 sigue abierto; matriz clínica UAT 0/36 aprobados.

## 1. Configurar las cuatro ramas y Environment desde una estación GitHub admin

Clonar o actualizar la rama del PR de este script en un equipo administrado. Se ejecuta con Python 3, sin bibliotecas adicionales.

Primero, **solo plan offline y pruebas con mocks**:

    python3 scripts/configure_github_release_governance.py
    python3 -m unittest discover -s tests/Scripts -p 'test_configure_github_release_governance.py' -v

Identificar un GitHub reviewer **independiente** con permisos sobre el repositorio, utilizando CLI GitHub autenticada:

    gh auth status
    gh api users/NOMBRE_REVISOR --jq '.id'

La salida es un ID numérico que debe validarse: nunca inventarlo ni sustituirlo por el usuario que ejecutará el script.

Obtener un token de corta duración con Administration:write en los DOS repositorios y ejecutar desde el equipo admin, **nunca dentro de GitHub Actions**:

    export HIPOCRATIQ_GH_ADMIN_TOKEN="$(gh auth token)"
    python3 scripts/configure_github_release_governance.py \
      --apply \
      --reviewer-id ID_NUMERICO_VERIFICADO \
      --acknowledgement HIPOCRATIQ_GITHUB_GOVERNANCE_ONLY
    unset HIPOCRATIQ_GH_ADMIN_TOKEN

El script falla antes del primer PUT si existen reglas previas, rulesets o Environment a reconciliar; no sobrescribe protecciones de terceros. Si hay fallas parciales después de algún PUT, no desactivar las protecciones; realizar auditoría y completar manualmente. Nunca publicar tokens o salidas sensibles.

**Verificación obligatoria, GET solamente**:

    export HIPOCRATIQ_GH_ADMIN_TOKEN="$(gh auth token)"
    python3 scripts/configure_github_release_governance.py --verify
    HIPOCRATIQ_GH_ADMIN_READ_TOKEN="$HIPOCRATIQ_GH_ADMIN_TOKEN" \
      python3 scripts/uat_github_admin_governance_readonly.py --live
    unset HIPOCRATIQ_GH_ADMIN_TOKEN

Exigir ambas salidas PASS y protección efectiva de main y develop en ambos repositorios.

### Checks configurados

| Repositorio | Rama | Required checks |
| --- | --- | --- |
| Backend | develop | lint; Actual Next.js to Laravel with PostgreSQL17 Redis8 (no PHI) |
| Backend | main | los anteriores; Release evidence / main P0 NO-GO (read-only) |
| Frontend | develop | web; Frontend CI |
| Frontend | main | los anteriores; Frontend E2E role routing |

Las protecciones exigen PR, un revisor independiente del último push, dismiss stale reviews, strict status checks, conversaciones resueltas, sin borrado ni force-push y se aplican también a administradores. No requieren historial lineal: el proyecto utiliza merge commits.

El Environment Backend hipocratiq-uat-readonly debe exigir revisor independiente con prevent self-review y una política custom con **solo develop**, sin main, tags ni comodines. Su trust IAM OIDC usa el subject repo:VaftecPeru/Hipocratiq_backend:environment:hipocratiq-uat-readonly, que **no incluye la rama**: por eso estas protecciones son requisitos previos a asumir el rol.

## 2. Instalación AWS IAM OIDC — autorización administrativa separada

Después del éxito verificado del punto 1, el admin AWS debe:

1. Corroborar cuenta/región, EC2/VPC y EBS para UAT y producción, sin reutilizar ID de documentación histórica.
2. Verificar que el proveedor IAM GitHub OIDC token.actions.githubusercontent.com con audience sts.amazonaws.com existe en la cuenta UAT.
3. Revisar el IaC infra/aws/uat-readonly-oidc-role.json y sus únicas cinco acciones Describe; validar sintaxis con aws cloudformation validate-template usando una sesión AWS de la cuenta y región autorizadas.
4. Preparar y aprobar de manera independiente el CloudFormation change set con CAPABILITY_NAMED_IAM. No ejecutar sin autorización explícita AWS. El rol HIPOCRATIQ-UAT-ReadOnly es de METADATOS, sin comandos SSM, datos, secretos o escrituras.
5. Registrar en Backend Actions variables (valores verdaderos y corroborados) HIPOCRATIQ_UAT_AWS_ACCOUNT_ID, HIPOCRATIQ_UAT_AWS_REGION, HIPOCRATIQ_UAT_INSTANCE_ID, HIPOCRATIQ_PROD_INSTANCE_ID, HIPOCRATIQ_UAT_VPC_ID, HIPOCRATIQ_PROD_VPC_ID, HIPOCRATIQ_UAT_READONLY_ROLE_ARN.
6. Mantener HIPOCRATIQ_AWS_UAT_RUN_ON_DEVELOP_PUSH=false y no activar HIPOCRATIQ_AWS_UAT_INVENTORY_ENABLED hasta una ventana aprobada.
7. Ejecutar el job UAT AWS Inventory manual con READ_ONLY_UAT_INVENTORY después de habilitar su workflow en la rama predeterminada mediante un cambio de gobernanza revisado; no hacer merge de toda la aplicación a main para obtener el botón.
8. Exigir job Approved AWS UAT instance metadata ONLY = SUCCESS (no SKIPPED), artifact vigente no vacío y revisión humana de UAT separado de producción.

## 3. Criterio clínico y release

La activación de GitHub/OIDC no es GO de producción. Mantener Backend PR #90 y Frontend PR #80 en DRAFT y issue #92 abierto hasta verificar HTTPS UAT real, OTP México +52, 36/36 pruebas clínicas, aislamiento A/B, firma inmutable, backup/restore y rollback, Android firmado y aceptación del cliente. Solo después solicitar autorizaciones distintas para fusionar a main y desplegar en AWS.
