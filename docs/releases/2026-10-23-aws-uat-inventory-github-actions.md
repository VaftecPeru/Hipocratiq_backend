# HIPOCRATIQ — Diagnóstico AWS UAT con GitHub Actions (solo lectura)

Estado inicial: **INACTIVO / NO-GO**. Fecha de preparación: 2026-10-23.

## Qué reemplaza y qué no

Este control permite comprobar EC2 y la conectividad de AWS Systems Manager desde GitHub Actions, sin depender del equipo remoto VAFTEC. **No requiere SSH, no ejecuta comandos dentro de EC2, no toca Docker/PostgreSQL/Redis y no despliega.** No debe confundirse con una UAT clínica ni con la prueba HTTPS de Next.js y Laravel.

No ejecutar el script antiguo \`scripts/run-hipocratiq-uat-release-gate.sh\` para este diagnóstico: dicho orquestador puede actualizar checkouts, iniciar contenedores, modificar bases temporales y enviar SMS reales.

## Prerrequisitos aprobados fuera de Git

- Inventario del **AWS Account ID**, región e **Instance ID** del entorno UAT exacto.
- Confirmar si UAT comparte o no el host productivo. Si comparte, este control exige etiquetado explícito UAT y aun así **no acredita aislamiento**; una evaluación adicional de infraestructura es obligatoria.
- Instancia EC2 etiquetada \`Environment=uat\` (también acepta qa/staging) y \`Project=HIPOCRATIQ\`; si no lo está, el diagnóstico falla y no crea etiquetas.
- GitHub Environment llamado \`uat-readonly\` con **revisores requeridos** y restricción de rama \`develop\`.
- AWS IAM OIDC configurado para \`token.actions.githubusercontent.com\` y un **rol read-only**, no la clave del perfil EC2 ni claves estáticas.
- Rol IAM separado de cualquier rol con permisos de escritura/despliegue. El rol no necesita \`ssm:SendCommand\`, \`ssm:StartSession\`, permisos para reiniciar instancias, editar SG, usar Secrets Manager o enviar SMS.

Política IAM mínima para ese rol (sustituir solo el Account ID en el ARN de rol/configuración, no copiar secretos):

~~~json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "ec2:DescribeInstances",
        "ec2:DescribeInstanceStatus",
        "ssm:DescribeInstanceInformation"
      ],
      "Resource": "*"
    }
  ]
}
~~~

La consulta \`sts:GetCallerIdentity\` funciona sin conceder permisos de escritura. Para la confianza OIDC del rol, restringir **audience** a \`sts.amazonaws.com\` y **subject** a \`repo:VaftecPeru/Hipocratiq_backend:environment:uat-readonly\`. Aplicar restricciones adicionales de organización si existen.

## Variables de GitHub (Backend)

Configurar por un administrador en **Settings → Secrets and variables → Actions → Variables**; nunca incluir datos médicos, OTP, contraseñas ni access keys.

| Variable | Formato / control |
|---|---|
| \`HIPOCRATIQ_UAT_AWS_INVENTORY_ENABLED\` | \`true\` sólo tras validar el target |
| \`HIPOCRATIQ_UAT_AWS_ACCOUNT_ID\` | Cuenta AWS exacta de 12 dígitos |
| \`HIPOCRATIQ_UAT_AWS_REGION\` | Región exacta, p. ej. \`us-east-2\` |
| \`HIPOCRATIQ_UAT_EC2_INSTANCE_ID\` | ID de EC2 UAT aprobado, \`i-...\` |
| \`HIPOCRATIQ_UAT_READONLY_ROLE_ARN\` | ARN del rol IAM OIDC solo lectura |
| \`HIPOCRATIQ_UAT_AWS_RUN_ON_DEVELOP_PUSH\` | \`false\` por defecto; \`true\` requiere autorización específica para inspeccionar ante push a develop |
| \`HIPOCRATIQ_UAT_AWS_PUSH_ACKNOWLEDGEMENT\` | \`INVENTORY_ONLY_NO_PRODUCTION\` sólo cuando se habilite la inspección opt-in por push |

Las variables solo se usarán después de revisión de PR, configuración de Environment y aprobación operativa. **No están provisionadas automáticamente por este PR**.

## Ejecución en GitHub Actions

El workflow \`.github/workflows/uat-aws-inventory-readonly.yml\` tiene tres vías separadas:

1. **Pull request a develop:** sólo valida sintaxis de Bash y diez escenarios sintéticos de bloqueo **sin AWS**.
2. **Push a develop (opt-in):** ejecuta inventario solo si un administrador definió los dos flags de aprobación, los targets exactos y el Environment con aprobación. Así puede utilizarse sin modificar \`main\`.
3. **workflow_dispatch:** solicita la frase \`INVENTORY_ONLY_NO_PRODUCTION\`. *GitHub exige que el workflow exista en la rama por defecto para aceptar el evento manual.* Como la rama por defecto actual es \`main\`, el despacho manual no quedará disponible hasta autorizar y publicar el workflow en esa rama. **No promover a main para habilitarlo sin aprobación explícita.**

En caso de UAT compartida con el host productivo, no asumir que la simple ejecución de \`Describe*\` demuestra separación. No ejecutar pruebas de carga ni comandos SSM contra dicho host.

## Diagnóstico esperado y evidencia

1. Identidad AWS STS corresponde exactamente a la cuenta autorizada.
2. EC2 pertenece al proyecto, su tag de entorno es estrictamente UAT/QA/staging y está running.
3. Comprobaciones de sistema e instancia EC2: \`ok\`.
4. Agente SSM aparece como \`Online\` (solo lectura de conectividad).
5. Imprimir \`UAT_AWS_INVENTORY=PASS\` sin IP privadas, datos clínicos, secretos ni ARN sensibles.
6. Registrar Run ID y resultado en la bitácora. Si cualquier condición falla: **NO-GO**, sin recuperar/acceder a la instancia por la fuerza.

Este control **no realiza** \`ssm:SendCommand\`, SSH, \`git fetch\`, \`docker compose\`, migraciones, OTP ni deploy.

## Gates pendientes para publicación

- HTTPS Web/API UAT verificado con workflow \`uat-https-readonly-manual.yml\`, previa aprobación de hostnames.
- Comprobar aislamiento entre bases y volúmenes, rutas de producción y UAT, sin leer contenidos clínicos.
- UAT con paciente y médicos ficticios por clínica A/B, roles y FHIR.
- OTP SMS México real +52 con autorización separada para gastos/recursos AWS y consentimiento del tester.
- Backup UAT y restore sobre una **segunda instancia** sin sobrescribir datos.
- Protección \`main\`, consentimiento del cliente y aprobación expresa para merge a \`main\` y despliegue productivo.

**Resultado por defecto: NO-GO hasta que todos esos gates se cumplan.**
