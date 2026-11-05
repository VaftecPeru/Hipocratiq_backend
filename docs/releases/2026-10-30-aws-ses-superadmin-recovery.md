# HIPOCRATIQ — Correo AWS SES y recuperación de Superadministrador

Corte: 30/10/2026. Documento de preparación; **no autoriza cambios de DNS, AWS o producción**.

## Diagnóstico verificado en código

- `StartPasswordReset` permite recuperar contraseña por correo y entrega un OTP con `IssueOtpChallenge` / `OtpCodeNotification`.
- `ResetPasswordWithOtp` invalida tokens y sesiones anteriores tras un cambio correcto.
- `config/mail.php` incluye el transporte Amazon SES y SMTP; `config/services.php` toma la región de `AWS_DEFAULT_REGION`.
- El correo `superadmin@hipocratiq.com` aparece en `HipocratiqUatAccessSeeder`, **no demuestra que el usuario o buzón estén configurados en producción**. El seeder UAT no debe ejecutarse en producción.
- Antes de esta corrección, `MailInfrastructureService::configured()` sólo aceptaba SMTP y `mail:probe` rechazaba SES aun con transporte SES seleccionado. La mejora distingue SES de SMTP y nunca considera una prueba automatizada con fakes como entrega real.

## Arquitectura recomendada

1. **Amazon Route 53:** administrar DNS; primero inspeccionar hosted zone autoritativa y registros MX, TXT (SPF/DMARC) y CNAME/TXT (DKIM). No reemplazar MX existentes sin inventario y autorización.
2. **Amazon SES:** correo **saliente** de Laravel (OTP, recuperación, avisos). Verificar dominio, DKIM, límites/sandbox en la región AWS correcta y remitente permitido. SES SMTP o API son alternativas; esta rama contempla `MAIL_MAILER=ses` y `mail:probe`.
3. **Buzón receptor:** contratar/usar proveedor actual de mailbox del dominio, o una solución inbound independiente aprobada. SES inbound a S3/Lambda **no es una bandeja de entrada de usuario** por sí solo; no instalar Postfix en EC2 de la app.
4. **Identidad del Superadministrador:** confirmar registro real, ID, roles y dirección con un administrador autorizado. Si el buzón `superadmin@hipocratiq.com` puede recibir, recuperar por OTP sin cambiar ID; si cambia correo, verificar nueva dirección, auditar cambio y conservar roles.

## Configuración objetivo (nunca commit de secretos)

```dotenv
MAIL_MAILER=ses
MAIL_FROM_ADDRESS=soporte@hipocratiq.com
MAIL_FROM_NAME=HIPOCRATIQ
MAIL_PROBE_RECIPIENT=destinatario-autorizado@example.test
AWS_DEFAULT_REGION=us-east-2
```

La región es **ejemplo de la región reportada para producción**, no evidencia de que SES esté activo en ella. El rol IAM asociado al runtime debe permitir exclusivamente acciones de envío SES necesarias en identidades autorizadas; preferir credenciales temporales EC2/IAM en lugar de claves AWS persistentes en `.env`. No usar el rol `HIPOCRATIQ-UAT-ReadOnly` para envío ni despliegue.

## Verificación y aceptación

- [ ] Identificar proveedor real de buzón y validar control del dominio.
- [ ] Verificar Route 53 NS/MX/TXT/DKIM y no alterar registros de web/API.
- [ ] Verificar identidad SES, región, sandbox, políticas IAM, límites, remitente SPF/DKIM/DMARC.
- [ ] Confirmar configuración efectiva del runtime Laravel, sin imprimir secretos; `mail:probe` no debe marcar SES como SMTP.
- [ ] Ejecutar `php artisan mail:probe destinatario-controlado@dominio.test` en entorno aprobado; el comando sólo acredita **solicitud de envío**, luego confirmar recepción real y Trace ID.
- [ ] Comprobar recuperación Superadministrador mediante OTP real, caducidad, intentos, rechazo/replay y revocación de sesiones, sin exponer contraseña ni códigos.
- [ ] Respaldos, aprobación de cambio y rollback antes de alterar correo productivo.
- [ ] Mantener `main` y producción intactos hasta evidencia y autorización explícitas.

No interpretar `Notification::fake()` ni `configured=true` como prueba de entrega efectiva; el resultado real depende de SES, IAM, DNS y buzón receptor.
