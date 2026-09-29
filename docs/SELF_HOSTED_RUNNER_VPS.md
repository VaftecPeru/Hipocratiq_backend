# HIPOCRATIQ — Runner CI self-hosted en VPS

Fecha: 29/09/2026  
Objetivo: ejecutar CI de Backend, Web y Android en infraestructura VAFTEC sin consumir minutos de runners GitHub-hosted.

## Arquitectura

Los workflows Linux usan la etiqueta:

`[self-hosted, linux, x64, hipocratiq-vps]`

Como Backend y Frontend están en repositorios separados, registrar una instancia de runner para cada repositorio cuando el propietario no disponga de runners compartidos a nivel organización.

Directorios sugeridos:

- `/opt/actions-runner/hipocratiq-backend`
- `/opt/actions-runner/hipocratiq-frontend`

No reutilizar el mismo directorio para dos servicios.

## Seguridad obligatoria

- Usar únicamente repositorios privados y ramas controladas.
- No ejecutar automáticamente código de Pull Requests en el VPS de producción.
- Ejecutar el runner con un usuario Linux dedicado, no como `root`.
- Mantener secretos fuera del repositorio.
- El acceso al grupo `docker` equivale prácticamente a privilegios elevados; limitarlo al usuario del runner.
- Mantener copia de seguridad antes de cualquier despliegue a producción.
- CI y deploy son operaciones distintas: que CI sea verde no autoriza por sí solo el despliegue.

Los workflows de esta rama se disparan por `push` a `develop`/`main` o por ejecución manual; se retiró el trigger automático por Pull Request para proteger el VPS.

## Registro del runner

Para cada repositorio:

1. GitHub → repositorio → Settings → Actions → Runners.
2. Seleccionar **New self-hosted runner**.
3. Elegir Linux / x64.
4. En el VPS, usar exactamente los comandos que GitHub muestra para descargar y configurar el runner.
5. Durante la configuración agregar la etiqueta personalizada `hipocratiq-vps`.
6. Instalarlo como servicio con los comandos `svc.sh` que GitHub muestra para Linux.
7. Confirmar en GitHub que el runner aparezca como **Idle/Online**.

El token de registro es temporal. No guardarlo en archivos del proyecto, documentación, historial shell compartido ni Git.

## Prerrequisitos del host

Verificar:

```bash
git --version
curl --version
docker --version
docker compose version
```

El workflow instala/configura sus toolchains mediante Actions:

- PHP 8.4 para Backend.
- Node.js 22 para Web.
- Java 17 + Android SDK + Flutter 3.44.4 para Android.

El usuario del runner debe poder usar Docker. Para Playwright, el sistema debe permitir la instalación de dependencias requeridas por Chromium.

## Orden de validación

### Backend
- Composer install/validate.
- Composer audit.
- Pint.
- PHPUnit.
- migraciones SQLite y PostgreSQL.
- performance smoke.
- Docker Compose.
- build de imagen.
- validación de pg_dump / pg_restore.

### Web
- npm audit.
- TypeScript.
- tests de regresión.
- ESLint.
- Next build.
- Docker build.
- Playwright E2E en ejecución manual o `main`.
- cleanup de procesos E2E al finalizar.

### Android
- dart format.
- flutter analyze.
- flutter test.
- launcher icons.
- APK de validación.
- AAB de validación.

## iOS

Ubuntu/VPS Linux no puede sustituir el toolchain macOS/Xcode. El job iOS queda manual y separado del gate Linux. Para un `.ipa` firmado se requerirá macOS + certificado Apple + provisioning profile. Esto no bloquea el release Web/Backend/Android definido para esta etapa.

## Gate de release

No promover `develop` a `main` hasta obtener:

1. Backend verde en VPS.
2. Web CI verde.
3. Web E2E verde.
4. Android verde.
5. UAT clínico y consentimiento verdes.
6. migraciones/backup/restore verificados.
7. auditoría final sin P0.
8. autorización de release.
