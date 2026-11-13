# HIPOCRATIQ — P0 #102: revisión confidencial del historial Git

Este procedimiento NO es una aprobación de seguridad, no autoriza un release, y no sustituye una revisión de credenciales en el proveedor. Ambos repositorios han tenido visibilidad pública; el historial de clones/forks puede persistir aunque se cambie su visibilidad.

## Evidencia automatizada al 13-11-2026
- Backend PR #183 / Frontend PR #102: Gitleaks de historial alcanzable falló con código 1 y `PRIVATE_TRIAGE_REQUIRED_NO_GO`. El escaneo de cambios recientes, secretos rastreados actuales y auditorías de dependencias de esos PR aprobaron.
- Hallazgo histórico no implica necesariamente credenciales vigentes, pero impide dar cierre a #102 sin comprobaciones independientes.
- Los logs públicos no deben incluir nombres de archivos, SHA de blobs relacionados, valores ni coincidencias de patrones.

## Pasos privados obligatorios (propietario o revisor Seguridad autorizado)
1. **Decisión de visibilidad:** documentar por escrito si el propietario autoriza que Backend/Frontend y su historia sean públicos; revisar contratos/licencias. No cambiar visibilidad automáticamente. Si procede privatizar, verificar accesos, forks y funcionamiento de GitHub Actions/Environments; cambiar visibilidad NO revoca secretos anteriormente expuestos.
2. **Inventario confidencial:** inspeccionar *localmente en un equipo seguro*, con el checkout completo, el historial, ramas/tags, releases, artefactos Actions y adjuntos. Clasificar cada hallazgo por fuente/proveedor, vigencia, alcance y carácter de ejemplo falso positivo. No enviar evidencias sensibles a issues públicos, logs, PR ni artefactos de Actions.
3. **Revisión por proveedor:** si una credencial real estuvo accesible, **revocar/rotar primero**, validar que el nuevo secreto esté solo en un almacén seguro, comprobar privilegios mínimos y analizar uso no autorizado (AWS, GitHub, OTP/Twilio, correo, BD y demás proveedores aplicables). No pegar credenciales al reporte. Un resultado antiguo puede seguir siendo peligroso aunque desaparezca del árbol actual.
4. **Historial y terceros:** evaluar limpieza de archivos/historia solo tras revocación y plan coordinado (force-push puede romper clones, ramas y PR); conservar evidencias bajo acceso restringido y considerar forks/cachés. No reescribir historial sin autorización expresa y plan de recuperación.
5. **Aplicación:** conservar `main` protegido, revisar que `develop` no incorpore nuevos secretos, verificar que la configuración de los entornos AWS y GitHub usa privilegios mínimos y que no hay credenciales compartidas entre UAT/producción.
6. **Acta confidencial:** registrar fecha, responsable, número total de casos revisados, clasificación (real / falso positivo / desconocido), confirmación de rotaciones o revocaciones, evidencias disponibles en ubicación privada y aceptación del responsable de Seguridad/PM. En GitHub publicar solo estado resumido `REVIEWED/NO_GO` o `REVIEWED/GO_SECURITY`, sin datos sensibles.

## Criterios para resolver #102
- Revisión del historial completa y auditoría del contenido de artefactos/releases autorizadas, no solo sus metadatos.
- Ninguna credencial comprometida sin revocar; decisiones sobre hallazgos documentadas y verificadas.
- Visibilidad de ambos repos aprobada por su propietario y controles GitHub efectivos.
- Aceptación escrita de Seguridad/PM, independiente de CI y UAT #92.
- Para release siguen requiriéndose #92, #111, #112, #113, evidencia AWS y autorización del responsable del proyecto.

**Prohibido:** subir secretos/PHI a GitHub, usar producción como banco UAT, comprar EC2 adicional sin autorización, desactivar el escáner para obtener verde o realizar merge a `main` sin las aprobaciones pertinentes.
