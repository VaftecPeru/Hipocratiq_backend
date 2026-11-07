# HIPOCRATIQ #112 — Evaluación sin escrituras del cifrado EBS

Este PR valida exclusivamente el reporte sanitizado que produce `scripts/prod_backup_metadata_readonly.py` cuando #156 y el opt-in de metadatos EBS tienen autorización. Nunca se llama a AWS desde este PR, ni se cambia cifrado, KMS, volúmenes o snapshots.

## Criterio fail-closed
- Sin reporte AWS real o con inconsistencias, `NO_GO`.
- Con uno o más volúmenes EBS adjuntos sin cifrar: `UNENCRYPTED_EBS_PRESENT_MIGRATION_APPROVAL_REQUIRED`.
- Con volúmenes cifrados pero referencias de clave KMS incompletas: `EBS_ENCRYPTED_KEY_METADATA_INCOMPLETE`.
- Con metadatos completos: solo `ENCRYPTION_METADATA_ONLY_HUMAN_VERIFICATION_REQUIRED`, **no** cierre de #112 ni GO a release.

Ejecutar con el artefacto sanitizado del job AWS autorizado: `python3 scripts/phase2_ebs_encryption_assessment.py --report <reporte_sanitizado.json>`. Los IDs de volúmenes, ARN KMS y datos clínicos **no** deben formar parte de comentarios públicos.

## Migración real — futuro change control explícito
Antes de transformar EBS: evidencia de respaldo íntegro con restauración aislada probada (#113), revisión del uso clínico de datos, política KMS y principio de mínimo privilegio, aprobación de presupuesto/ventana y plan de rollback. La migración requiere procedimiento separado y revisión operacional de snapshots, copia cifrada y nuevo volumen. No desprender, sustituir, reiniciar ni borrar el volumen actual con esta automatización.

La captura previa informaba raíz sin cifrar; debe confirmarse contra metadatos AWS actuales. `main` y producción permanecen sin cambios por este PR.
