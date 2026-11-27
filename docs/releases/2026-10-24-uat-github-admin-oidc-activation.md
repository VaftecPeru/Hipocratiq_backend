# HIPOCRATIQ — Activación controlada del gate AWS UAT

Fecha: 2026-10-24. **NO-GO producción.** Este PR no crea ni modifica recursos AWS ni políticas GitHub.

## Evidencia verificable

- Backend develop: 79081d1f3a7881049ef33437e5ca5779005c69dd, CI #40145562439 SUCCESS.
- Frontend develop: fea9867729069216112f41d86f0d163588e7afb5, CI #40130219762 SUCCESS.
- Backend y Frontend main: protected=false (confirmado por la API GitHub).
- AWS Inventory #40145562486: offline safety SUCCESS, job AWS SKIPPED y sin artifact; NO valida EC2/VPC reales.
- Release gate #40147547295: NO-GO, por PR DRAFT, main sin protección, issue P0 #92 OPEN e inventario AWS real ausente.
- Release Backend PR #90 y Frontend PR #80: DRAFT. No pasar a READY ni fusionar para saltar el guard.

## Paso 1. Comprobar plan GitHub antes de activar OIDC

Los repositorios HIPOCRATIQ son privados. GitHub especifica que required reviewers de Environments en GitHub Free/Pro/Team están disponibles solo para repositorios públicos. Confirmar la edición de GitHub que permite imponer required reviewers en estos repositorios privados. Si la edición no soporta esa protección, mantener OIDC e inventario real deshabilitados, o implementar un control equivalente independiente aprobado por Seguridad. NUNCA hacer público un repositorio clínico para activar revisiones.

Referencia: https://docs.github.com/en/actions/reference/workflows-and-actions/deployments-and-environments

## Paso 2. Protección GitHub requerida (administrador)

Configurar en ambos repositorios VaftecPeru/Hipocratiq_backend y VaftecPeru/Hipocratiq_Frontend las ramas develop y main:

- PR obligatorio, al menos un revisor diferente del autor, dismiss stale reviews, no bypass administrativo, resolver conversaciones.
- Required status checks estrictos, force-push y borrados bloqueados.
- No activar linear history sin comprobar que el flujo histórico basado en merge commits lo permita.
- Check names Backend (PR a develop y main): lint y Actual Next.js to Laravel with PostgreSQL17 Redis8 (no PHI). El workflow de integración Full Stack se ejecuta **en todos los PR** (sin filtros de rutas), usa frontend público pinneado al SHA aprobado y nunca accede a AWS real.
- Check adicional Backend main: Release evidence / main P0 NO-GO (read-only).
- Frontend develop: web y Frontend CI. Frontend main: web, Frontend CI y Frontend E2E role routing.
- No exigir trabajos de Flutter omitidos (SKIPPED) como check obligatorio.
- Verificar configuración real, no solo que la propiedad protected sea true.

## Paso 3. Environment GitHub Backend

Settings > Environments > hipocratiq-uat-readonly:

- Required reviewers con acceso al repositorio, y prevent self-review activo.
- Deployment branches: selected branches con exactamente develop.
- No permitir main, tags o patrones amplios.
- Probar que el Environment bloquea jobs hasta la aprobación; declararlo en YAML no lo configura automáticamente.

Trust de IAM existente exige exactamente:
repo:VaftecPeru/Hipocratiq_backend:environment:hipocratiq-uat-readonly

El claim anterior NO prueba la rama. Por eso debe protegerse develop y limitarse el Environment a esa rama.

## Paso 4. AWS UAT y rol por OIDC (administrador AWS)

- Confirmar cuenta AWS de UAT, región y EC2/VPC UAT, y registrar aparte los IDs verificables de producción para exclusión. UAT requiere infraestructura/secretos/volúmenes separados de producción.
- Verificar que existe el proveedor OIDC token.actions.githubusercontent.com, audiencia sts.amazonaws.com, sin duplicarlo.
- Revisar CloudFormation infra/aws/uat-readonly-oidc-role.json. Solo propone el rol HIPOCRATIQ-UAT-ReadOnly con cinco acciones Describe y sesión de una hora.
- Validar plantilla y revisar CloudFormation change set con CAPABILITY_NAMED_IAM. NO ejecutar el cambio sin autorización AWS separada.
- No añadir IAM admin, SSM shell, SMS, secretos ni escritura.

## Paso 5. Variables GitHub Actions (valores reales del administrador)

Variables en Backend:
- HIPOCRATIQ_UAT_AWS_ACCOUNT_ID: ID verificado de 12 dígitos
- HIPOCRATIQ_UAT_AWS_REGION: región UAT verificada
- HIPOCRATIQ_UAT_INSTANCE_ID: EC2 UAT real
- HIPOCRATIQ_PROD_INSTANCE_ID: EC2 producción real para excluir
- HIPOCRATIQ_UAT_VPC_ID: VPC UAT real
- HIPOCRATIQ_PROD_VPC_ID: VPC producción real para excluir
- HIPOCRATIQ_UAT_READONLY_ROLE_ARN: ARN exacto del rol IAM autorizado
- HIPOCRATIQ_AWS_UAT_INVENTORY_ENABLED: false hasta aprobación
- HIPOCRATIQ_AWS_UAT_RUN_ON_DEVELOP_PUSH: false por defecto
- HIPOCRATIQ_AWS_UAT_PUSH_ACKNOWLEDGEMENT: solo tras autorización explícita

NO introducir access keys AWS, OTP, contraseñas o PHI en GitHub Variables/Issues/logs. No inventar identificadores.

## Paso 6. Comprobar gobernanza de GitHub — SOLO GET, sin AWS

La auditoría propuesta está en scripts/uat_github_admin_governance_readonly.py.

Ejecución offline, no red:
    python3 -m unittest discover -s tests/Scripts -p 'test_uat_github_admin_governance_readonly.py' -v
    python3 scripts/uat_github_admin_governance_readonly.py

Ejecución de inspección administrativa, desde un equipo autorizado con token GitHub para leer Administration del repositorio:
    HIPOCRATIQ_GH_ADMIN_READ_TOKEN="$(gh auth token)" python3 scripts/uat_github_admin_governance_readonly.py --live

El proceso hace solo solicitudes GET allowlisted a la API GitHub. No imprime tokens, IDs ni respuestas completas. Si no hay acceso de administración, informa NO-GO sin cambiar configuración.

## Paso 7. Inventario AWS real, solo tras cumplir 1–6

El workflow uat-aws-readonly-inventory.yml tiene un job AWS opt-in que requiere rama develop protegida y Environment revisado. El workflow_dispatch puede no estar disponible si el workflow no existe en la rama predeterminada main. No afirmar que el botón Run workflow esté disponible sin comprobarlo.

La alternativa push a develop con flags de autorización requiere un push real aprobado; NO fabricar commits para disparar AWS. Mantener activación automática deshabilitada.

Exigir un job Approved AWS UAT instance metadata ONLY = SUCCESS, NO SKIPPED, y artifact no expirado hipocratiq-uat-aws-readonly-metadata cuyo contenido saneado verifique inventario. El inventario no es GO clínico y no prueba de manera independiente los IDs autoindicados de producción.

## Paso 8. GO/NO-GO clínico y merge main

Faltan UAT HTTPS Next+Laravel+PostgreSQL/Redis real con datos ficticios; OTP real mexicano +52; 36 casos de la matriz clínica sin P0/P1; aislamiento A/B, permisos, firmas, consentimientos, FHIR; backup cifrado/restore en segunda instancia; rollback; seguridad móvil, protección main, acta cliente y autorización separada de PR main y despliegue AWS.

Este PR solo prepara la auditoría; no ejecuta OIDC, CloudFormation, cambios de Settings GitHub, merges main o despliegues.
