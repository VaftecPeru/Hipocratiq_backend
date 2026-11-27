# HIPOCRATIQ — Rol AWS UAT por OIDC y camino a producción

**Corte 2026-10-24. ESTADO: PREPARADO; NO INSTALADO. Producción: NO-GO.**

Esta guía acompaña la plantilla infra/aws/uat-readonly-oidc-role.json.
Contiene solo IAM de lectura: NO crea EC2, VPC, RDS, SNS, SMS, cuentas ni usuarios.
La instalación del stack requerirá autorización específica del administrador AWS.

## 1. Premisas AWS e identificación de UAT

- El repositorio privado Backend fue creado 2026-07-07, ID 1292480446.
- El workflow existente es .github/workflows/uat-aws-readonly-inventory.yml.
- El administrador identifica y verifica por separado las cuentas, regiones,
  EC2 y VPC de UAT y producción (nunca confundir variables autodeclaradas con pruebas).
- UAT debe tener instancias/redes/volúmenes/secretos separados de producción,
  etiquetas Environment=uat y Project=HIPOCRATIQ, cifrado EBS, puertos seguros.
- Debe existir el proveedor IAM OIDC de GitHub en la cuenta UAT:
  https://token.actions.githubusercontent.com, audiencia sts.amazonaws.com.
  No crear un segundo proveedor si ya existe.
- Sujeto esperado: repo:VaftecPeru/Hipocratiq_backend:environment:hipocratiq-uat-readonly.
  Github soporta sujetos con IDs inmutables en repos creados desde 2026-07-15.
  Este repo es anterior, pero verificar si optó voluntariamente por claims personalizados.

## 2. Preflight local y CloudFormation

Sin conectarse a AWS:

    python3 -m json.tool infra/aws/uat-readonly-oidc-role.json > /dev/null
    python3 -m unittest discover -s tests/Scripts -p 'test_uat_oidc_readonly_role.py' -v

Con una sesión autorizada de IAM/AWS en cuenta y región verificadas,
validación sintáctica de solo lectura:

    aws cloudformation validate-template \
      --template-body file://infra/aws/uat-readonly-oidc-role.json \
      --region REGIÓN_UAT_VERIFICADA

El administrador revisa un CloudFormation change set llamado
hipocratiq-uat-oidc-readonly (CAPABILITY_NAMED_IAM). Comprobar que solo crea
o actualiza el rol HIPOCRATIQ-UAT-ReadOnly y ninguna otra infraestructura.
**NO ejecutar el change set sin autorización AWS separada.**

El rol tendrá máximo 1 hora de sesión y estas cinco acciones solamente:
ec2:DescribeInstances, ec2:DescribeInstanceStatus, ec2:DescribeVolumes,
ec2:DescribeSecurityGroupRules, ssm:DescribeInstanceInformation.
STS GetCallerIdentity no requiere concesión IAM explícita.
No agregar políticas adicionales ni acciones de escritura o shell remoto.

## 3. Controles GitHub que configura el administrador

Settings > Environments en VaftecPeru/Hipocratiq_backend:
crear hipocratiq-uat-readonly, permitir únicamente la rama develop,
activar aprobación de reviewers y evitar la autoaprobación cuando el plan lo soporte.
Si no se pueden hacer cumplir las revisiones de ese entorno privado,
mantener desactivadas las llamadas a AWS hasta contar con protección equivalente.

Configurar en Settings > Secrets and variables > Actions > Variables:

| Variable | Requisito |
|---|---|
| HIPOCRATIQ_UAT_AWS_ACCOUNT_ID | Cuenta AWS UAT de 12 dígitos verificada |
| HIPOCRATIQ_UAT_AWS_REGION | Región de EC2 UAT |
| HIPOCRATIQ_UAT_INSTANCE_ID | ID real de EC2 UAT |
| HIPOCRATIQ_PROD_INSTANCE_ID | ID real EC2 producción excluido |
| HIPOCRATIQ_UAT_VPC_ID | ID real VPC UAT |
| HIPOCRATIQ_PROD_VPC_ID | ID real VPC producción excluida |
| HIPOCRATIQ_UAT_READONLY_ROLE_ARN | Output del stack IAM autorizado |
| HIPOCRATIQ_AWS_UAT_INVENTORY_ENABLED | false hasta aprobación; true luego |
| HIPOCRATIQ_AWS_UAT_RUN_ON_DEVELOP_PUSH | false hasta autorización explícita |
| HIPOCRATIQ_AWS_UAT_PUSH_ACKNOWLEDGEMENT | READ_ONLY_UAT_INVENTORY solo autorizado |

No configurar llaves AWS, OTP, secretos, teléfono ni pacientes en variables GitHub.
El workflow usa credenciales temporales OIDC y allowed-account-ids.

GitHub muestra workflow_dispatch normalmente solo si el archivo existe en
la rama default main. Mientras main permanezca intacta, la alternativa es
push a develop en paths observados, con las flags y aprobaciones habilitadas.
NO crear pushes falsos para desencadenar inventarios AWS sin nueva autorización.

## 4. Evidencia que falta: AWS UAT real

Exigir en GitHub Actions que el job Approved AWS UAT instance metadata ONLY
termine COMPLETED/SUCCESS, no SKIPPED, y que el informe JSON indique
INVENTORY_PASS_NOT_RELEASE_APPROVAL, con IAM/STS, EC2 VPC, EBS, SSM
y reglas Security Group verificadas.
Los IDs de producción deben corroborarse independientemente:
diferencia entre variables autodeclaradas no demuestra aislamiento real.

El inventario no valida Docker, salud clínica, HTTPS, OTP ni backups.

## 5. Gates obligatorios antes de merge a main y deploy AWS

1. Backend, Frontend y móvil UAT: CI sobre SHAs exactos.
2. Aislamiento real y verificado de infraestructura UAT (no usar QA dentro de prod).
3. Web Next.js + Laravel + PostgreSQL + Redis con HTTPS y datos sintéticos.
4. OTP SMS México +52 real en UAT y 6 dígitos, expiración, 5 intentos, rate limit.
5. Seguridad: clínicas A/B, IDs ajenos, revocación, consentimientos, FHIR, roles.
6. Restauración de backup UAT cifrado en una segunda instancia y rollback ensayado.
7. Android UAT en dispositivos reales; app firmada y configuración segura.
8. Protección main con reviews y CI requerido en ambos repositorios.
9. Aceptación del cliente, cero defectos P0/P1 y acta firmada.
10. Autorización separada de PR main y despliegue controlado con rollback.

No hay en este PR una sola prueba de inventario AWS real, OTP real ni
aceptación clínica. Estado de producción **NO-GO**.

## Orden de ejecución

1. Revisar/autorizar PR IAM IaC; CI de tests offline.
2. Configurar IAM OIDC, rol, GitHub Environment y variables verificadas.
3. Ejecutar inventario real con aprobación, corregir hallazgos sin tocar prod.
4. Aprovisionar UAT independiente, TLS y servicios; ejecutar matriz UAT completa.
5. Ensayar backup, restore y rollback y verificar aceptación del cliente.
6. Congelar artefactos y hashes, configurar protección main/required checks.
7. Abrir PR de release Backend/Frontend (móvil con gate propio) hacia main,
   comprobar diff y gates. Merge/deploy únicamente con GO formal y autorización.
