# HIPOCRATIQ — Evidencia GitHub Actions de versiones en producción

Corte 30/10/2026. Objetivo: determinar si la versión observada en `https://hipocratiq.com/` puede vincularse de forma verificable a un SHA y a la EC2 productiva sin leer datos clínicos ni desplegar.

## Hallazgo

- EC2 producción *reportada en el inventario anterior*: cuenta `390736325457`, `us-east-2`, instancia `i-0f6789726d40b4122`, VPC `vpc-0245b564a2e609bf6`. Los identificadores deben verificarse con AWS real; no basta el texto de una issue.
- Frontend y Backend construyen Docker sin etiqueta `org.opencontainers.image.revision`. El SHA de un checkout Git en el host **no prueba** qué código está ejecutando un contenedor previamente construido.
- Los endpoints públicos actuales no tienen un contrato documentado de cabecera `X-Hipocratiq-Release-Sha`. Por ello, la ausencia de SHA significa **NO VERIFICADO**.
- El workflow histórico UAT está enfocado en infraestructura de ensayo separada y no debe hacerse pasar por verificación de producción.

## Nueva acción `production-version-evidence-readonly.yml`

1. **Offline:** tests sintéticos y validaciones fail-closed, sin AWS.
2. **HTTPS:** dos solicitudes GET sin credenciales a `https://hipocratiq.com/` y `https://hipocratiq.com/backend/api/health/ready`, sin seguir redirecciones ni publicar respuestas, cookies, cabeceras arbitrarias o datos personales; registra solo disponibilidad y un SHA de 40 caracteres si la aplicación lo anuncia explícitamente.
3. **AWS** (*deshabilitado por defecto*): solo cuando un administrador habilite **ambas variables** y el reconocimiento, exclusivamente en un push aprobado a `develop`, detrás del environment `hipocratiq-prod-readonly` con revisores. OIDC temporal limitado a lectura de STS, EC2 y metadatos de SSM; valida cuenta/instancia/VPC y health de EC2. **No ejecuta** SSM SendCommand, SSH, Docker, comandos remotos, queries DB, snapshots ni despliegue.
4. Artifacts redactados, retención 7 días. Resultado AWS aun verificado: `INFRASTRUCTURE_IDENTIFIED_SOURCE_SHA_UNVERIFIED`; **no equivale a versionado del código desplegado**, UAT, recuperación ni autorización de `main`.

## Configuración de GitHub Settings que debe realizar el administrador

En *repositorio Backend → Settings → Environments*, crear o validar `hipocratiq-prod-readonly`, exigir revisores independientes y restringir rama a `develop` protegida. Configurar variables (preferentemente a nivel Environment, no secrets en repositorio):

| Variable | Función |
|---|---|
| `HIPOCRATIQ_PROD_METADATA_ENABLED` | `true` para autorización opt-in |
| `HIPOCRATIQ_PROD_METADATA_ON_DEVELOP_PUSH` | `true` para una ejecución por push |
| `HIPOCRATIQ_PROD_METADATA_ACK` | `READ_ONLY_PROD_METADATA` |
| `HIPOCRATIQ_PROD_AWS_ACCOUNT_ID` | Cuenta verificada, no supuesta |
| `HIPOCRATIQ_PROD_AWS_REGION` | Región verificada |
| `HIPOCRATIQ_PROD_INSTANCE_ID` | Identificador de EC2 productiva verificado |
| `HIPOCRATIQ_PROD_VPC_ID` | VPC verificada |
| `HIPOCRATIQ_PROD_READONLY_ROLE_ARN` | ARN exacto de rol `HIPOCRATIQ-PROD-ReadOnly` |

**Importante:** el `if` del job se evalúa antes del Environment; si el repositorio no permite leer allí las variables para evaluar ese `if`, ubicar únicamente los flags de habilitación en el nivel repo con revisión administrativa y mantener identificadores/rol en Environment. No habilitar flags sin reviewers y branch protection funcionando. El evento `workflow_dispatch` solo estará visible cuando este workflow forme parte de la rama por defecto; no fusionar a `main` para eludir controles.

En AWS, el administrador debe aprobar y configurar el rol antes de habilitar flags. Trust OIDC: proveedor GitHub, audiencia `sts.amazonaws.com`, sub de entorno exacto `repo:VaftecPeru/Hipocratiq_backend:environment:hipocratiq-prod-readonly` y limitar acceso mediante revisores/ramas. **Política exclusiva**: `ec2:DescribeInstances`, `ec2:DescribeInstanceStatus`, `ssm:DescribeInstanceInformation` y `sts:GetCallerIdentity`, además de lectura de metadatos permitida por IAM. No permitir `ssm:SendCommand`, `ssm:StartSession`, escritura EC2, acceso a BD, SES o secretos. No crear roles o infraestructura con coste automáticamente.

## Cómo se comprobará el SHA real (fase separada, también sin PHI)

Para demostrar *qué código está vivo* es necesario un origen de identidad del artefacto **desde el runtime**, no solo del repositorio. Opciones sujetas a revisión y autorización:

- Incluir en imágenes Docker futuras una etiqueta OCI `org.opencontainers.image.revision` fijada desde el SHA aprobado en el build y un atestado/digest del artefacto, luego contrastarlo **con la imagen del contenedor en ejecución** usando inspección estrictamente controlada.
- Alternativamente, exponer una cabecera de versión del artefacto construido o inventario de deployment firmado. Un SHA comunicado por HTTP, sin contraste con el artefacto/EC2, es solo una afirmación de aplicación.
- Para conocer las imágenes *ya desplegadas*, se requiere un canal de inspección de contenedores autorizado; EC2 Describe y SSM Describe no pueden hacerlo. Cualquier SSM RunCommand necesitaría permisos y aprobación adicionales; este workflow **no lo hace**.

Los SHA Frontend `main` y Backend `main` se deben leer de GitHub y comparar únicamente cuando exista evidencia runtime. **Nunca** afirmar equivalencia porque ambos builds o CI estén en verde.

## Demo de cliente

Referencia: [bitácora #150](https://github.com/VaftecPeru/Hipocratiq_backend/issues/150), reunión propuesta 02/11/2026 17:00 PET. Mostrar login y panel solo con cuentas ficticias y pantallas disponibles. La identidad productiva sigue **NO VERIFICADA** hasta prueba real. La auditoría privada #102, clínica real #92 y restauración #113 siguen independientes; ninguno se cierra con la ejecución de este workflow.
