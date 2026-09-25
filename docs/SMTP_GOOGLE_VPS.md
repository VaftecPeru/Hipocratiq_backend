# SMTP de Google desde el VPS

HIPOCRATIQ no necesita ejecutar Postfix/Exim ni convertirse en servidor público de correo.

La arquitectura recomendada es:

```
Laravel / HIPOCRATIQ (VPS)
        |
        | TLS / STARTTLS
        v
smtp.gmail.com:587
        |
        v
Gmail / Google Workspace
```

## Configuración recomendada

Para una cuenta Gmail o Google Workspace que permita App Passwords:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=cuenta-google@dominio.com
MAIL_PASSWORD=APP_PASSWORD_DE_16_CARACTERES
MAIL_TIMEOUT=20
MAIL_EHLO_DOMAIN=hipocratiq.com
MAIL_FROM_ADDRESS=cuenta-google@dominio.com
MAIL_FROM_NAME="HIPOCRATIQ"
```

No usar la contraseña normal de Google en `MAIL_PASSWORD`.

Si se desea usar `soporte@hipocratiq.com` como remitente con una cuenta distinta, esa dirección debe existir como cuenta o alias autorizado en Google. De lo contrario Google puede reescribir o rechazar el remitente.

## ¿Necesito crear un proyecto OAuth en Google Cloud?

No para esta implementación. HIPOCRATIQ utilizará SMTP autenticado con una App Password. La pantalla de consentimiento OAuth de Google Cloud sólo sería necesaria si posteriormente migramos a Gmail API/OAuth 2.0.

## Pasos en Google

1. Activar verificación en dos pasos en la cuenta Google.
2. Crear una App Password para HIPOCRATIQ/VPS.
3. Copiar la App Password al archivo `.env` del VPS.
4. No guardar la App Password en GitHub.
5. Reiniciar el contenedor de API y los workers si consumen configuración cacheada.
6. Limpiar y reconstruir configuración Laravel:

```bash
php artisan config:clear
php artisan config:cache
```

7. Ejecutar una prueba real:

```bash
php artisan mail:test-smtp destinatario@dominio.com
```

En Docker:

```bash
docker compose exec app php artisan mail:test-smtp destinatario@dominio.com
```

## Diagnóstico rápido desde el VPS

Conectividad TLS hacia Google:

```bash
openssl s_client -starttls smtp -connect smtp.gmail.com:587 -crlf
```

Si el puerto 587 está bloqueado por el proveedor VPS, la aplicación no podrá utilizar Gmail SMTP aunque las credenciales sean correctas.

## Google Workspace SMTP Relay

Si HIPOCRATIQ migra a Google Workspace administrado, puede reemplazarse `smtp.gmail.com` por el servicio SMTP Relay de Workspace. Ese cambio debe hacerse en configuración/infraestructura; no requiere modificar el dominio clínico de la aplicación.

## Criterio de aceptación

SMTP se considera listo cuando:

- `mail:test-smtp` termina con código 0;
- el correo llega a un buzón externo;
- un OTP real puede emitirse y recibirse;
- no hay credenciales SMTP en Git;
- el panel de Infraestructura muestra correo transaccional como configurado.
