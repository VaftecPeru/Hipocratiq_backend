# B-109 — Gate técnico UAT OTP AWS

## Objetivo

Validar de extremo a extremo la integración de OTP telefónico con AWS End User Messaging Notify sobre la versión estable de `develop`, sin modificar `main` ni producción.

Base de trabajo:

- Backend `develop`: `c65c4a56cc8e9a62a0467b0196dec809b1be8bfc`
- Actividad origen: B-099
- Rama: `feature/b109-otp-aws-uat-gate`

## Principios de seguridad

- Activar `OTP_PHONE_PROVIDER=aws_notify` únicamente en UAT durante este gate.
- No almacenar el OTP telefónico en la base de datos.
- No registrar OTP, credenciales AWS ni secretos en logs, Git o bitácora.
- Preferir un IAM Role/Instance Profile en EC2 y la cadena de credenciales por defecto del AWS SDK.
- Mantener `OTP_FIXED_CODE` vacío.
- Mantener la política local alineada con AWS: 5 minutos y máximo 5 intentos.
- No promover a `main` hasta completar todos los controles.

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

1. Solicitar OTP con un teléfono UAT válido en formato E.164.
2. Confirmar que AWS entrega el SMS.
3. Confirmar que la respuesta de HIPOCRATIQ devuelve `challenge_id`, nunca el OTP.
4. Ingresar el código recibido.
5. Confirmar que AWS devuelve `VALID`.
6. Confirmar consumo del challenge.
7. Confirmar creación de sesión web o Bearer token Sanctum móvil según el cliente.

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
