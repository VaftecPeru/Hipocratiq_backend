# HIPOCRATIQ — Diagnóstico B-109 de infraestructura UAT (solo lectura)

Fecha de preparación: 23/10/2026. Estado: **preparado, NO ejecutado en VPS**.

## Objetivo y límites

Confirmar si el entorno UAT histórico de HIPOCRATIQ **existe y sigue aislado** antes de habilitar cualquier prueba HTTPS, OTP mexicano +52, migración o UAT clínica. El equipo remoto VAFTEC se encontró **offline** en la última comprobación. Nunca interpretar los datos históricos como el estado actual de AWS.

### Evidencia histórica del código

El script versionado `scripts/run-hipocratiq-uat-release-gate.sh` hace referencia a estos recursos, cuya existencia no ha sido comprobada en vivo:

| Recurso | Referencia a verificar |
|---|---|
| Backend QA checkout | `/srv/hipocratiq/qa-20261013/backend-b109` |
| Frontend QA checkout | `/srv/hipocratiq/qa-20261016/frontend-b108` |
| PostgreSQL UAT | Contenedor `hipocratiq-b109-postgres` |
| Red Docker UAT | `hipocratiq-b109-net` |
| Archivo de configuración UAT | `/tmp/hipocratiq-b109.env` (**solo existencia/permisos**) |
| Producción Backend | `/srv/hipocratiq/backend` (**NO ejecutar validaciones allí**) |
| Producción Frontend | `/srv/hipocratiq/frontend` (**NO ejecutar validaciones allí**) |
| PostgreSQL de producción | `back-api-postgres` (**solo nombre y metadatos de montaje para comprobar que no comparte datos**) |

## Requisitos operativos

1. Restablecer la conexión autorizada **Remote Desktop Commander / VAFTEC**. No conectar SSH directamente ni cambiar credenciales sin nueva autorización.
2. Confirmar el host autorizado para inspección y la política de acceso de lectura a Docker; no usar `sudo` para modificar configuraciones.
3. Tener el script disponible en un **checkout QA aislado** de GitHub, sin modificar ni actualizar los checkouts de producción. La revisión del PR solo prepara el script; su merge y ejecución requieren sus autorizaciones correspondientes.
4. Ejecutar exclusivamente el comando **sin parámetros**:

```bash
bash scripts/diagnose-uat-infrastructure-readonly.sh
```

Para inspeccionar sus limitaciones sin consultar host ni Docker:

```bash
bash scripts/diagnose-uat-infrastructure-readonly.sh --help
```

Si el checkout QA real tiene otra ubicación, usar las variables `HIPOCRATIQ_QA_BACKEND`, `HIPOCRATIQ_QA_FRONTEND`, `HIPOCRATIQ_QA_PG`, `HIPOCRATIQ_QA_NETWORK` y `HIPOCRATIQ_QA_ENV_FILE`, pero **solo** tras comprobar que apuntan a objetos QA/UAT y jamás a producción.

## Qué inspecciona

- Host, kernel, RAM disponible, espacio libre, puertos escuchando; no atribuye puertos a servicios.
- Git `HEAD` y rama de dos checkouts QA, sin fetch, checkout, merge, reset ni pull.
- Archivo `.env` **solo metadatos**: existencia y bits de permisos. Nunca leer valores.
- Contenedor PostgreSQL B-109: presencia, estado y reporte de health Docker (sin conectarse a PostgreSQL).
- La ruta de montaje PostgreSQL QA frente al contenedor productivo conocido (sin leer archivos).
- Red Docker QA, nombres de miembros y alerta si contiene contenedores con denominaciones de producción.
- Resultado `BLOCKED` / `UNVERIFIED` y clasificación release fija `NO_GO`.

## Prohibido en este diagnóstico

No ejecutar:

- `docker start`, `docker exec`, `docker run`, `docker compose up`, migraciones ni `pg_restore`.
- `git fetch`, `git pull`, `git checkout` o cambios en repositorios.
- `php artisan otp:aws-uat-gate`, envíos SMS, STS/Notify, creación de clínicas o pacientes.
- Volcar `docker inspect .Config.Env`, `cat .env`, logs clínicos, tokens, OTP, claves o bases de datos.
- Probar el endpoint `hipocratiq.com` de producción como si fuese UAT.
- Escribir los resultados en una ruta productiva o subir secretos a Issues/PR/bitácora.

## Interpretación y próximos pasos

El script confirma solo **inventario y segregación básica de infraestructura**. Si todo aparece `PASS`, **NO significa que UAT clínica esté aprobada ni que el servidor sea seguro para ejecutar OTP**.

Antes de continuar, revisar manualmente:

1. UAT DNS+TLS real e IP/instancia de destino (sin apuntar a producción).
2. Separación de credenciales, políticas IAM, secretos, bases, volúmenes y backups.
3. PostgreSQL UAT con datos sintéticos, sin información clínica real.
4. Servicio Web Next.js y Backend Laravel con SHAs identificados en UAT.
5. Firewall, TLS, sesiones, CORS y controlador de acceso por tenant.
6. Respaldo UAT, restauración **en instancia diferente** y rollback ensayado.
7. Autorización específica de SMS real AWS/OTP +52.
8. Acta de UAT clínica del cliente y aprobación independiente para `main` y despliegue.

**Release status: NO-GO**, incluso con un diagnóstico de infraestructura satisfactorio.

## Evidencia a compartir

Registrar solamente: fecha/hora, identificador no secreto del host autorizado, SHA de checkouts QA, estado Docker de recursos QA, confirmación de que volúmenes/redes están separados, capacidad y recuento de bloqueos. Nunca registrar valores de `.env`, nombres de pacientes, SMS, secretos ni volcado de configuración Docker.
