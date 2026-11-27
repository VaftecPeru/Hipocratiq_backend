# HIPOCRATIQ — revisión de credenciales expuestas en ejemplos públicos

Corte: 26 de octubre de 2026. **Investigación P0; no hay evidencia aquí de uso productivo ni autorización de deploy.**

## Evidencia reproducible sin imprimir secretos

La API GitHub confirma que `VaftecPeru/Hipocratiq_backend` y `VaftecPeru/Hipocratiq_Frontend` son repositorios **públicos**.

La plantilla histórica `.env.example` del Backend tenía asignaciones **no vacías** a `DB_PASSWORD` y `REDIS_PASSWORD`. Se detectaron en los árboles `develop` y `main`; **no se puede inferir si esos textos coinciden con credenciales válidas de UAT o producción**.

Este PR:
- Vacía **solo** esas dos asignaciones en la plantilla versionada, sin añadir nuevos valores compartidos.
- Incorpora `scripts/check_public_env_template.py` y cinco regresiones para fallar de manera segura si se publican claves/tokens/contraseñas en el ejemplo.
- Ejecuta el control en CI **antes** de copiar `.env.example` a `.env`.
- No modifica secretos efectivos, instancias, bases, contenedores, GitHub Settings ni AWS.

## Trabajo obligatorio de seguridad (fuera de este PR)

1. Administrador autorizado: comparar **privadamente** las credenciales de BD/Redis operativas de todos los entornos con valores históricos de Git; **no volcar valores ni hashes reversibles en PRs, issues, logs o Sheets**.
2. Si existe coincidencia o no puede descartarse, **rotar** los secretos desde los servicios responsables con ventana y rollback aprobados, invalidar los anteriores y comprobar reconexión controlada. No dejar como contraseña los ejemplos históricos.
3. Auditar el historial Git completo, releases, issues y artifacts Actions con herramientas aptas para búsqueda de secretos, sin imprimir hallazgos públicamente. La limpieza del archivo actual **no elimina** versiones antiguas de Git ni clones.
4. Confirmar con el propietario si los repositorios deben seguir públicos. Si la publicación no está autorizada, gestionar cambio de visibilidad y permisos con administrador y revisión de dependencias; la visibilidad no sustituye protección de ramas.
5. Registrar acta de Seguridad/PM con hallazgos resumidos (sin secretos), impacto, rotaciones confirmadas y cierre de [issue #102](https://github.com/VaftecPeru/Hipocratiq_backend/issues/102), nunca cerrar únicamente porque CI esté verde.

## Gates independientes de producción

Este PR de remediación preventiva no certifica confidencialidad ni autoriza `main` o AWS. Mantener [issue clínico #92](https://github.com/VaftecPeru/Hipocratiq_backend/issues/92) abierto y [Backend PR #90](https://github.com/VaftecPeru/Hipocratiq_backend/pull/90) y [Frontend PR #80](https://github.com/VaftecPeru/Hipocratiq_Frontend/pull/80) en borrador mientras falten UAT/OTP/OIDC, restauración y aceptación.
