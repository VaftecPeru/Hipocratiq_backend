# B-109 — Gate técnico UAT OTP AWS

## Objetivo

Validar de extremo a extremo el OTP telefónico con AWS End User Messaging usando directamente `SendNotifyCodeVerification` y `ValidateNotifyCodeVerification`, sin depender de una `Notify Configuration` en estado `ACTIVE`. HIPOCRATIQ adopta México (`MX`, `+52`) como mercado principal; Perú (`PE`) y Estados Unidos (`US`) continúan soportados como mercados secundarios.

Base de trabajo:

- Backend `develop`: `c65c4a56cc8e9a62a0467b0196dec809b1be8bfc`
- Actividad origen: B-099
- Rama: `feature/b109-otp-aws-uat-gate`

## Principios de seguridad

- Activar `OTP_PHONE_PROVIDER=aws_notify` únicamente en UAT durante este gate.
- No usar `DescribeNotifyConfigurations` ni exigir `Notify Configuration = ACTIVE` como precondición del OTP.
- No almacenar el OTP telefónico en la base de datos.
- No registrar OTP, credenciales AWS ni secretos en logs, Git o bitácora.
- Preferir un IAM Role/Instance Profile en EC2 y la cadena de credenciales por defecto del AWS SDK.
- Mantener `OTP_FIXED_CODE` vacío.
- Mantener la política local alineada con AWS: 5 minutos y máximo 5 intentos.
- No promover a `main` hasta completar todos los controles.

## Ejecución consolidada — una sola acción

Después de completar dependencias, migraciones e IAM/Instance Profile, el gate funcional se ejecuta con:

```bash
php artisan otp:aws-uat-gate
```

El comando tiene guardas obligatorias:

- Sólo permite `APP_ENV=testing|uat|local`.
- Exige por defecto que la base conectada sea exactamente `hipocratiq_b109_uat`.
- Usa `APP_PRIMARY_COUNTRY=MX` como país objetivo por defecto y exige que el teléfono UAT coincida con ese país.
- Verifica por STS que el SDK esté usando el rol `HIPOCRATIQ-EC2-Notify-UAT`.
- Verifica mediante `ListNotifyCountries` que el país objetivo esté disponible para `SMS / CODE_VERIFICATION / BASIC`.
- México es el mercado principal y la prueba de aceptación se ejecuta con un número `+52`.
- No consulta ni depende de una `Notify Configuration ACTIVE`.
- Resuelve `originationIdentity` automáticamente y de forma determinista: valida primero pools transaccionales que realmente contengan una identidad `SMS` del país objetivo; luego Sender IDs registrados/transaccionales; finalmente números activos `SMS` locales o con envío internacional.
- Si `AWS_NOTIFY_ORIGINATION_IDENTITY` está definido, el gate ya no lo acepta a ciegas: debe coincidir con una identidad AWS realmente utilizable para el país objetivo.
- Descubre una Notify Code Configuration `NUMERIC / 6 dígitos / 5 minutos / 5 intentos`; si no existe, el gate UAT crea automáticamente `HIPOCRATIQ-<PAIS>-OTP` con esa política y tags de proyecto/entorno.
- Si existen varias configuraciones de código válidas, la selección es determinista y prioriza `HIPOCRATIQ-<PAIS>-OTP`; no requiere elección manual.
- `SendNotifyCodeVerification` genera y entrega el OTP; `ValidateNotifyCodeVerification` confirma el código usando el mismo `referenceId`.
- La ausencia de `Notify Configuration ACTIVE` deja de ser un bloqueo. La Code Configuration deja de ser un bloqueo manual porque el gate puede crearla en UAT. Los bloqueos reales quedan reducidos a permisos IAM, ausencia de una identidad de origen utilizable/registrada o rechazo de la llamada directa de AWS.
- Activa `aws_notify` sólo en memoria durante esa ejecución; no escribe secretos ni configuración AWS en Git o `.env`.
- Ejecuta internamente `otp:aws-check` antes de enviar.
- Crea una clínica y un paciente temporales únicamente en la base B-109 UAT.
- Envía un SMS real por `/api/v1/auth/otp/request`.
- Solicita el OTP mediante entrada oculta y nunca lo imprime.
- Valida dispositivo incorrecto, código incorrecto, expiración y reutilización del challenge.
- Valida OTP correcto por AWS, emisión Bearer Sanctum, `/api/v1/auth/me`, logout y revocación.
- Elimina los datos temporales al finalizar, salvo uso explícito de `--keep-data`.

Para probar explícitamente un mercado secundario, debe declararse de forma intencional:

```bash
php artisan otp:aws-uat-gate --target-country=PE
```

La prueba principal de aceptación B-109 debe realizarse con un número mexicano válido en formato E.164 (`+52` seguido de 10 dígitos), porque México es el mercado objetivo del cliente.

El flujo directo de AWS exige un `originationIdentity` para enviar el SMS, pero ese valor es independiente de una `Notify Configuration ACTIVE`. El gate intenta reutilizar una identidad SMS existente del account antes de solicitar configuración manual.

Si se quiere forzar una identidad concreta puede usarse:

```bash
php artisan otp:aws-uat-gate \
  --origination-identity=<PhoneNumberId|SenderId|PoolId|ARN>
```

No registrar el OTP recibido ni el Bearer Token como evidencia. La evidencia válida es la tabla final `PASS/FAIL`, IDs no secretos de configuración y los logs sanitizados.

## Gate 1 — Dependencias reproducibles

Desde la rama B-109:

```bash
composer update aws/aws-sdk-php --with-all-dependencies --no-interaction
composer validate --strict
composer install --no-interaction --prefer-dist
```

Resultado esperado:

- `aws/aws-sdk-php >= 3.399.0`
- `composer.json` y `composer.lock` sincronizados.
- Instalación reproducible sin actualizar dependencias no relacionadas de forma innecesaria.

## Gate 2 — Configuración UAT

Configurar en el entorno UAT, nunca en Git:

```dotenv
APP_PRIMARY_COUNTRY=MX
OTP_PHONE_PROVIDER=aws_notify
OTP_TTL_MINUTES=5
OTP_MAX_ATTEMPTS=5
OTP_FIXED_CODE=
AWS_NOTIFY_REGION=us-east-2
AWS_NOTIFY_ORIGINATION_IDENTITY=<ARN o identidad administrada por AWS>
AWS_NOTIFY_CODE_CONFIGURATION=<ncc-id o ARN>
AWS_NOTIFY_CONFIGURATION_SET=
```

Luego:

```bash
php artisan config:clear
php artisan otp:aws-check
```

El comando debe terminar en SUCCESS antes de enviar el primer SMS.

## Gate 3 — Migraciones y QA automatizado

```bash
php artisan migrate --force
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/Auth/AwsOtpReadinessTest.php
php artisan test --compact tests/Feature/Auth/AwsNotifyOtpFlowTest.php
php artisan test --compact tests/Feature/Auth/OtpAuthenticationTest.php
php artisan test --compact tests/Feature/Auth/LoginTest.php
```

Verificar además que el OTP por correo y el provider local no tengan regresiones.

## Gate 4 — Prueba SMS real

Flujo mínimo:

1. Solicitar OTP con un teléfono mexicano UAT válido en formato E.164 (`+52` + 10 dígitos).
2. Laravel invoca directamente `SendNotifyCodeVerification` con la identidad de origen y la Code Configuration.
3. Confirmar que AWS entrega el SMS.
4. Confirmar que la respuesta de HIPOCRATIQ devuelve `challenge_id`, nunca el OTP.
5. Ingresar el código recibido.
6. Laravel invoca `ValidateNotifyCodeVerification` con el mismo `referenceId`.
7. Confirmar que AWS devuelve `VALID`.
8. Confirmar consumo del challenge.
9. Confirmar creación de sesión web o Bearer token Sanctum móvil según el cliente.

No registrar el OTP recibido como evidencia.

## Gate 5 — Casos negativos

Validar como mínimo:

- código incorrecto;
- código vencido;
- máximo de 5 intentos;
- resend y reemplazo del challenge anterior;
- teléfono inválido;
- proveedor AWS temporalmente no disponible;
- challenge consumido o invalidado;
- no exposición de IDs/ARN/OTP/credenciales en respuestas públicas.

## Release gate integral en una sola acción

Con México como mercado principal de HIPOCRATIQ, el flujo operativo oficial queda consolidado en:

```bash
HIPOCRATIQ_UAT_BACKEND_URL=https://<backend-uat> \
bash scripts/run-hipocratiq-uat-release-gate.sh
```

El orquestador:

1. actualiza la rama B-109 sin merge;
2. valida PostgreSQL UAT;
3. ejecuta PHP syntax, Pint y las suites críticas Auth/OTP;
4. ejecuta `otp:aws-uat-gate --target-country=MX` para AWS Notify, SMS real +52, negativos y Sanctum;
5. actualiza la rama B-108 sin merge;
6. ejecuta QA Web;
7. ejecuta QA Flutter/Android y genera APK/AAB apuntando exclusivamente al backend UAT HTTPS indicado;
8. emite hashes SHA-256 y estado `READY_FOR_USER_TESTS_AND_DEVELOP_AUTHORIZATION`.

El script rechaza `https://hipocratiq.com/backend` como backend del APK integral para impedir que una build UAT termine probando contra producción.

Este gate no realiza merge a `develop` ni `main`. Esos pasos requieren autorización explícita posterior a las pruebas del usuario.

## Definition of Done

B-109 puede marcarse Completado sólo cuando:

- Composer es reproducible con AWS SDK.
- Migraciones aplican correctamente.
- Pint y pruebas Auth/OTP quedan verdes.
- `otp:aws-check` queda verde en UAT.
- SMS real recibido.
- Código correcto validado como `VALID`.
- Escenarios negativos validados.
- Sanctum/session confirmado.
- Evidencias registradas sin datos sensibles.

Después de B-109 se evalúa el cierre de B-098/B-099 y se crea una actividad independiente para promover `develop → main`.
