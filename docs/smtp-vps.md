# SMTP productivo en VPS — HIPOCRATIQ

## Objetivo

El VPS ejecuta Laravel, queue worker y scheduler. El VPS **no necesita convertirse en servidor de correo completo**. La aplicación entrega correo saliente a un proveedor SMTP externo.

## Arquitectura recomendada

```
HIPOCRATIQ API / queue-worker
        |
        | TCP 587 + STARTTLS
        v
Google Workspace SMTP Relay
        |
        v
Destinatario
```

Ventajas:

- evita administrar reputación de IP como servidor de correo;
- evita mantener Postfix/Exim, antispam y colas SMTP propias;
- conserva los OTP y correos transaccionales en Laravel;
- los secretos permanecen en el entorno del VPS, no en GitHub.

## Opción A — Google Workspace SMTP Relay por IP (recomendada para VPS estable)

Variables:

```dotenv
MAIL_MAILER=smtp
MAIL_AUTH_MODE=ip_relay
MAIL_SCHEME=
MAIL_HOST=smtp-relay.gmail.com
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_TIMEOUT_SECONDS=15
MAIL_EHLO_DOMAIN=hipocratiq.com
MAIL_FROM_ADDRESS=soporte@hipocratiq.com
MAIL_FROM_NAME=HIPOCRATIQ
MAIL_PROBE_RECIPIENT=correo-de-prueba@dominio.com
```

En Google Workspace se debe autorizar la IP pública del VPS y restringir los remitentes al dominio aprobado.

## Opción B — Gmail/Workspace SMTP con credenciales

Variables:

```dotenv
MAIL_MAILER=smtp
MAIL_AUTH_MODE=credentials
MAIL_SCHEME=
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=cuenta-autorizada@dominio.com
MAIL_PASSWORD=<secreto-de-aplicacion>
MAIL_TIMEOUT_SECONDS=15
MAIL_EHLO_DOMAIN=hipocratiq.com
MAIL_FROM_ADDRESS=cuenta-autorizada@dominio.com
MAIL_FROM_NAME=HIPOCRATIQ
MAIL_PROBE_RECIPIENT=correo-de-prueba@dominio.com
```

No usar la contraseña normal de la cuenta en el archivo de aplicación. El secreto debe configurarse directamente en el VPS/secret manager.

## Prueba de conectividad

Después de modificar el `.env`:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan mail:probe
```

También puede indicarse un destinatario explícito:

```bash
php artisan mail:probe qa@dominio.com
```

Un resultado satisfactorio devuelve un Trace ID que también aparecerá en el correo recibido.

## Colas

OTP por correo se envía de manera inmediata para poder informar un error de entrega al usuario. Otras notificaciones que implementan `ShouldQueue` son procesadas por `queue-worker`.

## Seguridad

- nunca guardar `MAIL_PASSWORD` en Git;
- limitar acceso al `.env` productivo;
- mantener TLS/STARTTLS;
- restringir remitentes en el relay;
- usar una cuenta o relay dedicado para HIPOCRATIQ;
- rotar el secreto si se sospecha exposición.

## Servidor SMTP propio en el VPS

Es técnicamente posible instalar Postfix/Exim, pero no es la opción recomendada para este proyecto. Requiere al menos PTR/rDNS, SPF, DKIM, DMARC, monitoreo de reputación, control de rebotes, protección anti-abuso y administración de colas. El código HIPOCRATIQ permanece compatible con un relay SMTP propio, pero la operación debe tratarse como infraestructura de correo separada.
