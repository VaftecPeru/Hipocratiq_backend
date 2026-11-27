# HIPOCRATIQ — Corrección de restauración PostgreSQL (#113)

**Estado: CORRECCIÓN DEFENSIVA DE CÓDIGO. No certifica restore real ni autoriza producción.**

## Defecto observado

El comando Artisan de restauración aceptaba un ID y la opción de confirmación coincidente; el servicio invocaba pg_restore --clean --if-exists sobre la **base predeterminada**, sin impedir el uso de una base productiva. Verificar SHA-256 del respaldo no certifica el aislamiento del destino.

## Corrección incorporada

1. Rechazo absoluto en APP_ENV=production; incluso si se habilita el flag.
2. BACKUP_RESTORE_ENABLED=false como valor por defecto.
3. Solo en entorno NO productivo, el host y base deben coincidir exactamente con BACKUP_RESTORE_TARGET_HOST y BACKUP_RESTORE_TARGET_DATABASE autorizados.
4. El nombre debe comenzar por hipocratiq_restore_ y ser distinto del de la base origen registrada en el manifiesto.
5. Todas las validaciones suceden ANTES de modificar el manifiesto, leer el dump o lanzar pg_restore.
6. Se conserva la opción CLI de confirmación del ID; no se ejecutan comandos ni se crean recursos AWS en este PR.

Ejemplo solo para infraestructura de ensayo aislada preexistente (la configuración por sí sola no certifica aislamiento):

    APP_ENV=testing
    BACKUP_RESTORE_ENABLED=true
    BACKUP_RESTORE_TARGET_DATABASE=hipocratiq_restore_qa
    BACKUP_RESTORE_TARGET_HOST=host-qa-previamente-verificado

La conexión DB_DATABASE / DB_HOST también debe apuntar exactamente al destino de ensayo. El responsable debe confirmar que jamás dirige al servidor o base productivos.

## Evidencia real para cerrar #113

- Inventario autorizado de backup cifrado, origen, retención y hash/tamaño.
- Restauración efectiva en PostgreSQL preexistente aislado, revisión de red, IAM, SG, host y base, resultados, tiempos e integridad.
- Acta firmada por responsable; referencias sin datos clínicos ni credenciales.
- Rollback de aplicación probado con SHA de Backend/Frontend y procedimientos compatibles con migraciones.
- Aprobación formal antes de cualquier ejecución en entorno real.

Las pruebas de este PR usan Storage y Process simulados; su resultado no es una restauración real. El usuario había diferido el inventario de snapshots: no se inspecciona ni provisiona AWS en esta corrección.
