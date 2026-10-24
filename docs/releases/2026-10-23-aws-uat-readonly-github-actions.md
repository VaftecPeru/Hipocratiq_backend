# HIPOCRATIQ — Inventario AWS UAT de solo lectura por GitHub Actions

**Estado al crear esta guía:** infraestructura UAT no verificada. Producción: **NO-GO**.

## Qué hace el workflow

- En un PR a develop, ejecuta exclusivamente pruebas de seguridad Python sin credenciales ni acceso a AWS. Los servicios AWS se simulan con respuestas ficticias.
- Un segundo job puede consultar metadatos reales si el administrador habilita explícitamente UAT_INVENTORY_ENABLED y configura las variables GitHub de la siguiente sección. Se ejecuta sólo al recibir un push a develop o un despacho manual aprobado; nunca desde pull_request.
- Adquiere un token GitHub OIDC temporal y asume un rol AWS exclusivo de UAT.
- Ejecuta únicamente STS GetCallerIdentity y EC2 DescribeInstances, DescribeVolumes, DescribeSecurityGroupRules. No realiza SSH, SSM, cambios EC2, Docker, migraciones, lecturas SQL, OTP, SMS, despliegues o manejo de datos clínicos.
- Bloquea si EC2 o VPC UAT son iguales a producción, si la etiqueta Environment no es UAT/QA/staging, si Project no es HIPOCRATIQ, si hay EBS sin cifrado o si hay puertos peligrosos abiertos a Internet.
- Publica un JSON no clínico en GitHub Actions Artifacts con retención de 7 días.

El inventario es seguro **sólo** si IAM también limita al rol a las consultas de lectura indicadas.

## Preparación que debe realizar un administrador

1. Identificar o provisionar EC2 y VPC UAT **independientes** de producción. No usar una carpeta QA dentro de la instancia productiva como sustituto de aislamiento de infraestructura.
2. Etiquetar EC2 con Environment=uat y Project=HIPOCRATIQ. Identificar los IDs de EC2/VPC UAT y producción.
3. Crear el rol AWS HIPOCRATIQ-UAT-ReadOnly y asociarlo a GitHub OIDC. Audiencia esperada: sts.amazonaws.com. Subject de confianza EXACTO: repo:VaftecPeru/Hipocratiq_backend:environment:hipocratiq-uat-readonly.
4. Otorgar solamente ec2:DescribeInstances, ec2:DescribeVolumes, ec2:DescribeSecurityGroupRules y sts:GetCallerIdentity. No conceder ec2:*, iam:*, ssm:SendCommand, SMS ni permisos de escritura.
5. En GitHub Backend, Settings > Environments, crear hipocratiq-uat-readonly. Configurar revisores obligatorios y restringir ramas a develop. El campo environment en YAML por sí solo NO crea una política de revisiones.
6. En Settings > Secrets and variables > Actions > Variables, configurar:

| Variable | Descripción |
|---|---|
| HIPOCRATIQ_AWS_UAT_INVENTORY_ENABLED | false por defecto; true únicamente tras aprobar UAT/IAM |
| HIPOCRATIQ_UAT_AWS_ACCOUNT_ID | Cuenta AWS UAT de 12 dígitos |
| HIPOCRATIQ_UAT_AWS_REGION | Región EC2 UAT, p.ej. us-east-2 |
| HIPOCRATIQ_UAT_READONLY_ROLE_ARN | ARN IAM del rol dedicado HIPOCRATIQ-UAT-ReadOnly |
| HIPOCRATIQ_UAT_INSTANCE_ID | EC2 UAT aprobada |
| HIPOCRATIQ_PROD_INSTANCE_ID | EC2 producción a excluir |
| HIPOCRATIQ_UAT_VPC_ID | VPC UAT aprobada |
| HIPOCRATIQ_PROD_VPC_ID | VPC producción a excluir |

No almacenar llaves de AWS, OTP, credenciales de BD, tokens, teléfonos ni datos clínicos en variables. Si faltan controles, el preflight falla sin conexión AWS.

## Disparadores y main protegida por decisión del proyecto

La suite OFFLINE funciona en pull_request y push hacia develop. El inventario AWS solo se activa con bandera true, identity AWS UAT y el Environment autorizado.

GitHub generalmente exige que un workflow con workflow_dispatch exista en la rama predeterminada para mostrar Run workflow. Como main NO puede modificarse en este momento, no se debe prometer disparar manualmente el inventario desde la UI. El disparador compatible sin tocar main es push a develop, exclusivamente con la bandera habilitada y aprobación Environment.

No crear commits artificiales para desencadenar consultas a AWS sin autorización. El workflow no inicia ninguna infraestructura UAT.

## Definición de resultado

- INVENTORY_PASS_NOT_RELEASE_APPROVAL: EC2, VPC, rol, tags, SG y cifrado EBS inspeccionados satisfactoriamente. **No es un GO**.
- NO_GO: falta una condición requerida o la EC2 no cumple controles. Bloquear despliegue.
- Job AWS skipped: no se ha habilitado inventario real, por lo tanto el servidor no ha sido diagnosticado; no es evidencia de salud de AWS.

Este inventario no demuestra contenedores funcionales, acceso HTTPS, OTP +52, clínicas A/B, backup/restore, rollback ni aceptación del cliente. Cada una de estas etapas requiere evidencias y aprobación separadas antes de cualquier merge a main o despliegue productivo.

## Próximos pasos para cerrar UAT

Validar inventario AWS, construir UAT independiente, conectar Next.js y Laravel con HTTPS, PostgreSQL y Redis reales, ejecutar casos de la matriz clínica con datos ficticios, validar SMS México con autorización expresa, restaurar copia UAT aislada, exigir protección main y aceptación del cliente.
