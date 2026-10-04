# SMTP en VPS — HIPOCRATIQ

HIPOCRATIQ no aloja un servidor de correo propio. El VPS actúa como cliente SMTP de Google para correo transaccional y OTP.

## Opción A — Credenciales SMTP

Usar cuando la cuenta emisora permite contraseña de aplicación.

```dotenv
MAIL_MAILER=smtp
MAIL_AUTH_MODE=credentials
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_SCHEME=
MAIL_USERNAME=cuenta-emisora
MAIL_PASSWORD=secreto-generado-en-google
MAIL_TIMEOUT_SECONDS=15
MAIL_EHLO_DOMAIN=hipocratiq.com
MAIL_FROM_ADDRESS=cuenta-emisora
MAIL_FROM_NAME=HIPOCRATIQ
MAIL_PROBE_RECIPIENT=correo-de-prueba
```

## Opción B — Google Workspace SMTP Relay por IP

Usar únicamente si el dominio está administrado en Google Workspace y la IP pública del VPS fue autorizada en el servicio SMTP Relay.

```dotenv
MAIL_MAILER=smtp
MAIL_AUTH_MODE=ip_relay
MAIL_HOST=smtp-relay.gmail.com
MAIL_PORT=587
MAIL_SCHEME=
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_TIMEOUT_SECONDS=15
MAIL_EHLO_DOMAIN=hipocratiq.com
MAIL_FROM_ADDRESS=cuenta-del-dominio
MAIL_FROM_NAME=HIPOCRATIQ
MAIL_PROBE_RECIPIENT=correo-de-prueba
```

## Aplicar cambios en producción

Después de modificar el `.env` del contenedor/API:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan mail:probe
```

También puede indicarse el destinatario manualmente:

```bash
php artisan mail:probe qa@dominio.com
```

El comando muestra sólo datos no sensibles y devuelve un Trace ID si la entrega SMTP fue aceptada.

## Validación funcional

Una vez validado `mail:probe`, ejecutar una prueba real del flujo de login/registro con OTP por correo. Si el transporte SMTP falla, HIPOCRATIQ descarta el desafío pendiente y devuelve indisponibilidad temporal en lugar de dejar un OTP marcado como enviado.
