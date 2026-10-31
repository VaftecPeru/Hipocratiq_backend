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

## Mantenimiento del gate — 30/10/2026

**Invariante:** no cerrar incidencias P0 sin pruebas ni sustituir aprobaciones clínicas o restauración real por CI sintético. La ruta UAT original sigue vigente mientras no se apruebe una política web alternativa por responsables autorizados.

### Corrección de cobertura P0

El gate `scripts/release_go_no_go.py` del Backend verifica ahora el estado de **#113 (backup/restauración)** además de #92 (clínica) y #102 (seguridad). Antes la comprobación de #113 estaba únicamente en el preflight web; ambos deben rechazar cambios con recuperación no acreditada. Su estado diferido se registra como pendiente, **no como restauración demostrada**. Las pruebas sintéticas verifican apertura/fallo, cierre documental y evaluación sobre la API GitHub; tampoco validan la existencia de backups.

### Por qué el preflight web permanece NO-GO

`scripts/controlled_web_live_gate.py` requiere actualmente **verificación manual independiente** fuera del flujo automático y retorna NO-GO aunque se cierren los issues. Se trata de un cierre intencional: ningún valor de un manifiesto autocreado, ni un comentario, ni un `SUCCESS` sintético acredita por sí solo aceptación clínica, auditoría de seguridad, backup/restauración ni autorización de despliegue.

Para hacer viable un GO futuro sin debilitar los gates, primero formalizar y aprobar un cambio de política con: evidencia clínica por rol/tenant, auditoría privada #102, comprobación de IAM/EC2/volúmenes, copia cifrada restaurada en destino aislado, rollback probado, aprobación de cambio y aprobación de release por revisores independientes. Implementar la verificación de esas evidencias en un **PR independiente**, con pruebas negativas sobre falsificación, SHAs incorrectos, caducidad y ausencia de revisores. Hasta la aprobación de esa política, mantener fallando el gate.

### Secuencia de cierre

1. Confirmar que los SHA de Frontend y Backend son los candidatos exactos, con checks obligatorios y ramas protegidas.
2. Cerrar #92 con acta clínica sobre datos ficticios y #102 tras auditoría privada y remediación.
3. Inspeccionar respaldo real, ejecutar restauración aislada y cerrar #113 únicamente después de evidencia.
4. Disponer de inventario AWS real de solo lectura. No presentar recursos productivos como si fueran UAT; si UAT no se financia (#111), documentar, aprobar e implementar la política alternativa propuesta antes de permitir publicación.
5. Registrar autorización final del responsable sobre los SHAs y una ventana de despliegue, *separada* del merge a `main`.

**Prohibido** modificar `main` o publicar AWS únicamente porque los scripts y las pruebas del propio gate están en verde.
