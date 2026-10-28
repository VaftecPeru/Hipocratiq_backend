# HIPOCRATIQ — contrato de evidencias para release web en AWS existente

Estado: **NO-GO hasta aprobación documentada y verificada**. Este procedimiento no habilita despliegue ni modifica el gate antiguo.

## Decisión de alcance
UAT AWS independiente no se aprovisionará en el presente release por presupuesto (issue #111). Se mantiene el alcance web Next.js + Laravel, sin publicar móvil. No se debe sustituir una prueba clínica real por un resultado CI, ni reutilizar metadatos de producción como si fueran UAT.

## Evidencia mínima obligatoria para un futuro gate alternativo
Cada registro del manifiesto debe tener `status: approved`, `backend_sha`, `frontend_sha`, `evidence_id`, `approved_by` y `approved_at` UTC verificables; referencias no deben contener datos clínicos, tokens ni contraseñas.

| Registro obligatorio | Evidencia autenticada y criterio de aceptación |
| --- | --- |
| clinical_web_signoff | Matriz web/API por rol y tenant con datos sintéticos, incidencias cerradas y aprobación nominativa del responsable clínico (#92) |
| security_review | Auditoría privada del historial completo, ramas, tags, releases y artefactos; resolución y rotación de secretos comprometidos; aprobación del responsable (#102) |
| aws_readonly_inventory | Acta de producción existente: cuenta, región, instancia, VPC, SSM, EBS, cifrado, SG y comprobaciones, obtenida con rol OIDC de solo lectura; no etiquetar como UAT |
| encrypted_backup | Identificador de respaldo previo al cambio, cifrado comprobado y retención documentada |
| restore_drill | Restauración real en destino aislado, prueba de integridad, tiempo de recuperación y aprobación; nunca restaurar sobre producción |
| rollback_plan | Procedimiento probado para revertir Frontend y Backend con SHA exactos, migraciones compatibles y responsable |
| production_oidc_review | Rol dedicado a producción distinto de UAT-readonly; permisos mínimos y Environment protegido con revisores |
| change_approval | Solicitud de cambio vinculada al SHA, ventana, aprobador, procedimiento smoke, monitoreo y rollback |

## Orden operativo
1. Validar CI del SHA candidato; impedir merge con fallos o trabajos incompletos.
2. Completar auditoría #102 **en canal privado** y cierre fundamentado.
3. Ejecutar aceptación clínica #92 con datos sintéticos y firmar resultados.
4. Inspeccionar AWS producción de solo lectura; diseñar backup cifrado y restauración sin afectar producción. Si no es posible aislar el ensayo, mantener NO-GO y solicitar decisión específica de costos.
5. Habilitar un gate alternativo **por PR independiente**, con evaluaciones positivas y negativas, verificaciones independientes de evidencias y aprobación de política. El script `controlled_web_release_evidence.py` actual es **preliminar** y solo evalúa metadatos; un resultado PASS suyo no autoriza merge.
6. Tras GO documentado, solicitar al usuario autorización explícita de merge a `main`; desplegar únicamente con aprobación separada, Environment protegido, backup previo, smoke tests y rollback probado.

## Criterios de rechazo
Sin aceptación clínica, con auditoría de secretos pendiente, sin cifrado o restauración comprobada, AWS OIDC de producción ausente, SHA distinto o evidencia no verificable: **NO-GO**. No desactivar ni simular el gate UAT anterior hasta que el nuevo sea aprobado y efectivo.
