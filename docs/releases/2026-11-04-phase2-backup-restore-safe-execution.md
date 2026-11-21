# HIPOCRATIQ #113 — Evidencia de respaldo/restauración PostgreSQL aislada

Esta mejora incorpora un **validador offline de atestaciones**. Solo admite un documento JSON de campos booleanos predeterminados y no imprime valores, rutas, hashes, credenciales ni datos clínicos. Un conjunto de indicadores `true` significa **declaración del operador pendiente de revisión independiente**, nunca una restauración demostrada.

## Ensayo aislado autorizado (NO se ejecuta desde GitHub Actions)
1. El administrador aprueba por escrito la prueba, ubicación **no productiva**, segmentación de red, controles IAM, base destino `hipocratiq_restore_*`, presupuesto, custodia y datos sintéticos.
2. Preparar PostgreSQL aislado existente, generar respaldo sintético con `pg_dump` y verificar el manifiesto SHA-256. No copiar datos de pacientes reales a runners de CI.
3. En ese entorno aislado, configurar `APP_ENV=testing`, `BACKUP_RESTORE_ENABLED=true`, `BACKUP_RESTORE_TARGET_HOST` y `BACKUP_RESTORE_TARGET_DATABASE` según los controles existentes en Laravel. Verificar `DB_HOST` y `DB_DATABASE` contra ese destino **antes** de invocar el procedimiento real.
4. Ensayar `php artisan backups:restore <id> --confirm=<id>` **solo** dentro del sistema no productivo ya autorizado; comprobar restauración, integridad, tiempos y recuperación/reversión. Nunca ejecutar en EC2 productiva.
5. Rellenar una atestación con únicamente: `schema_version=1`, `target_environment=isolated_nonproduction`, `used_synthetic_data`, `isolated_target_confirmed`, `backup_sha256_verified`, `restore_command_succeeded`, `post_restore_integrity_checked`, `production_source_untouched`, `rollback_rehearsed` (booleanos).
6. Ejecutar `python3 scripts/phase2_backup_drill_evidence.py --attestation <archivo_privado.json>` desde un lugar autorizado, sin subir el documento a GitHub, y pedir validación independiente con hora, operador y resultados de base. La herramienta **no comprueba por sí misma** la veracidad de una declaración.
7. Registrar exclusivamente referencia a evidencia confidencial y aprobación, no su contenido sensible, en #113.

## Estado
En CI solo se prueban fixtures sintéticos. No se conecta PostgreSQL, no se restaura ningún dump, no se generan snapshots, no cambia producción. La decisión previa de diferir ejecución real no se revoca por publicar este PR; #113 sigue abierto hasta autorización y prueba real.
