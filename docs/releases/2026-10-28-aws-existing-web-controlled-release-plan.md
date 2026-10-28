# HIPOCRATIQ · Plan de release web controlado en AWS existente
Fecha: 2026-10-28. Estado: PROPUESTA, sin autorización de merge ni despliegue.

## Alcance aprobado para preparación
- Reutilizar EC2 de producción existente en cuenta AWS 390736325457, región us-east-2, instancia i-0f6789726d40b4122.
- Alcance exclusivamente Web Frontend Next.js y Backend Laravel. El cliente difiere UAT independiente (#111) y no autoriza crear recursos AWS con costo ni publicar móvil.
- Esta autorización permite diseño, CI, validaciones de solo lectura y documentación; NO modifica producción ni implica aceptación clínica o autorización de despliegue.

## Estado de evidencia
- Frontend main: e8a25ae8b8ef6af0890a076b0042a409078d86af.
- Backend RC PR #90 y develop: f020db34a41d80f11457c4c7176e052f41310e30 (corte inicial, verificar SHA nuevamente antes del release).
- Backend main protegido; lint e integración Next.js/Laravel/PostgreSQL17/Redis8 pasaron.
- Gate release_go_no_go.py permanece NO-GO por #92, #102 y falta de inventario REAL UAT AWS. NO marcar UAT como realizada ni manipular resultados.
- EBS sin cifrado confirmado previamente, backup/restaurabilidad #113 diferida; el script scripts/deploy-production.sh requiere BACKUP_AT_REST_VERIFIED=true y UAT_RESTORE_EVIDENCE auténticos. Si no existen, no invocar el script con valores ficticios.

## Diseño de control de cambios (alternativa propuesta, no implementada)
1. Seguridad #102: dueño confirma visibilidad de repos y alcance contractual. Auditoría de commits completos, ramas, tags, releases y artefactos en canal confidencial; revisar tokens y rotaciones. Acta y aprobación de seguridad antes de merge.
2. Clínica #92: establecer matriz web/API acotada aprobada por responsable, aislamiento tenant A/B, RBAC, permisos médico/paciente, expediente, login y OTP conforme alcance; usar SOLO datos sintéticos/desidentificados. Adjuntar resultados verificables, firma clínica y decisión de alcance móvil diferido.
3. Infraestructura AWS: administrador verifica por AWS CLI con OIDC de **solo lectura** cuenta, región, EC2, VPC, volumen, cifrado, SSM agent, SG y salud HTTP; metadatos redactados en artifact no sensible. No presentar inventario de producción como inventario de UAT.
4. Backup: validar respaldo cifrado en reposo y restauración ensayada en entorno aislado, con acta y sumas de verificación; si no es viable sin recursos nuevos, bloqueo de release hasta obtener autorización.
5. Despliegue automatizado futuro: Environment GitHub hipocratiq-production con main restringida y revisores; rol IAM OIDC **exclusivo de producción** y permisos mínimos para SSM sobre EC2 exacta (no usar HIPOCRATIQ-UAT-ReadOnly); sin SSH/llaves guardadas en workflow, con SHA inmutable, locking y permisos mínimos.
6. Predeploy: CHANGE_ID, SHA aprobado, plan de migración backward compatible, snapshot/backup con restaurabilidad comprobada, rollback documentado y ventana aprobada. Parar si alguna evidencia es inválida.
7. Deploy: solo tras autorización explícita separada. Ejecución mediante SSM con trazabilidad, health checks HTTPS/Backend, comprobación version SHA Frontend+Backend, smoke tests sin PHI, observabilidad y rollback de código. Nunca restaurar bases automáticamente ante migraciones.

## Política del gate y decisión pendiente
El gate vigente exige AWS UAT real para develop SHA exacto. Al no existir UAT independiente autorizada, **no se debe desactivar o falsificar la comprobación**. Para permitir una futura vía web alternativa, el propietario de proyecto/seguridad/clínica deben aprobar por escrito un RFC de cambio de criterio con controles equivalentes reales, y después desarrollar y testear una nueva política de release fail-closed en un PR separado. Hasta entonces: NO-GO.

## Requisitos para GO
- #92 cerrado con evidencia clínica y aceptación.
- #102 cerrado con revisión y aprobación de seguridad.
- Política de release aprobada y gate CI obligatorio en verde para el SHA definitivo, ya sea con UAT real o alternativa aprobada y verificada.
- Evidencias de backup cifrado + restauración real y plan de migración/rollback.
- AWS OIDC producción, Environment, inventario de solo lectura y smoke tests validados.
- Aprobación explícita final de merge a main y despliegue, separados de esta autorización de preparación.
