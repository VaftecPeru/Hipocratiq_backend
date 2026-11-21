# HIPOCRATIQ — Gate de despliegue Backend seguro

Fecha 2026-10-24. Estado: PR QA, SIN MERGE ni despliegue.
El script Backend se mantiene scripts/deploy-production.sh,
no ejecutarlo sobre AWS antes de completar UAT y emitir un GO firmado.

## Cambios de seguridad propuestos

- Umask 077 desde el inicio, respaldos .dump en modo 0600 y directorio en 0700.
- Prohibición de backup a una ruta simbólica o sobre un archivo existente.
- RELEASE_APPROVED_SHA: SHA Git completo de 40 caracteres que debe
  coincidir EXACTAMENTE con origin/main después del git fetch.
- RELEASE_APPROVAL=APPROVED_FOR_PRODUCTION: confirmación humana obligatoria.
- RELEASE_CHANGE_ID: referencia de cambio/acta aprobado.
- UAT_RESTORE_EVIDENCE: referencia de restauración validada en UAT aislado.
- BACKUP_AT_REST_VERIFIED=true: el administrador atestigua que el volumen EBS,
  el acceso y retención de ese backup fueron auditados y cifrados.
- Si faltan estos controles, el proceso sale antes de git fetch, Docker,
  migraciones o escrituras de backup.

Estas variables son GUARDAS procedimentales, no comprueban por sí solas
una firma criptográfica ni el cifrado EBS. Deben corroborarse con el acta
de release y la inspección AWS del volumen y permisos.

## Pruebas seguras (solo en checkout CI/QA aislado)

    bash -n scripts/deploy-production.sh
    bash scripts/test-deploy-preflight-failclosed.sh

La segunda prueba NUNCA suministra una autorización de release completa
y debe fallar ANTES de tocar repositorios, Docker o PostgreSQL.

## Advertencia obligatoria sobre rollback

El script actual puede revertir el SHA de código y reconstruir contenedores,
pero **NO revierte automáticamente las migraciones PostgreSQL aplicadas**.
Por eso es imprescindible la estrategia de migraciones expand/contract,
la evidencia de restauración a otra base de datos UAT y un procedimiento
manual probado de recuperación antes de cualquier producción.

El dump predeploy local no es prueba de una restauración exitosa.
No almacenar PHI sin aislamiento, cifrado, permisos mínimos y retención
aprobada. No copiar el backup a GitHub Artifacts.

## Cierre GO/NO-GO

GO requiere UAT real Next+Laravel+PG/Redis/OTP +52, roles multi-tenant,
backup cifrado y restore ensayado, main protegida, aprobación del cliente,
change ID aprobado, SHA exacto y administrador de guardia.

Hasta completar esos requisitos, NO-GO. Este PR no autoriza cambios en
main ni comandos de AWS.
