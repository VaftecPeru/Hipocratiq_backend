# HIPOCRATIQ #156 — OIDC PROD ReadOnly: validación contractual automática

Este PR valida en cada PR a `develop` la plantilla IAM y las precondiciones de GitHub Actions **sin realizar llamadas AWS**. Bloquea permisos de escritura, desviaciones de `aud`, `sub` o de los opt-ins de la job. No habilita el Environment ni crea roles.

## Activación real pendiente de administrador
1. Revisar en AWS el rol existente `HIPOCRATIQ-PROD-ReadOnly`: trust exactamente limitado a `repo:VaftecPeru/Hipocratiq_backend:environment:hipocratiq-prod-readonly`, `aud=sts.amazonaws.com`, acciones Describe exclusivamente. La plantilla no prueba la política desplegada.
2. Verificar en GitHub `hipocratiq-prod-readonly`: required reviewer independiente, prevent self-review, sin bypass de administradores, regla de rama `develop` realmente guardada.
3. Comprobar `develop` protegida y variables del Environment `HIPOCRATIQ_PROD_AWS_ACCOUNT_ID`, `HIPOCRATIQ_PROD_AWS_REGION`, `HIPOCRATIQ_PROD_INSTANCE_ID`, `HIPOCRATIQ_PROD_VPC_ID`, `HIPOCRATIQ_PROD_READONLY_ROLE_ARN`.
4. Solo tras revisión, habilitar las tres variables `HIPOCRATIQ_PROD_METADATA_*` **a nivel repositorio**, porque el `if` de la job se evalúa antes de entrar al Environment. Ejecutar un **push autorizado a develop** en una ruta de trigger y aprobar el Environment. `workflow_dispatch` no activa el job AWS por diseño.
5. Registrar URL del run, SHA, revisor, verificación STS/EC2/SSM y artifact sanitizado. No imprimir credenciales ni información clínica.

El inventario metadata no verifica Docker SHA ni backups restaurables. Solo tras aprobación adicional se activa el inventario EBS #113/#112; nunca se ejecuta AWS desde este PR. `main` y producción siguen NO-GO.
