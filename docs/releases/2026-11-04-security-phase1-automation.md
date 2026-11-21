# HIPOCRATIQ — P0 #102, CI automatizado de seguridad de cambios

Alcance de este PR: implementar controles de CI **aislados** en Backend sin modificar ramas protegidas ni AWS. Se ejecuta en cada PR hacia `develop` o `main` y bajo ejecución manual.

## Controles automáticos

- Escáner existente `check_public_env_template.py` y `check_current_tracked_credentials.py` bloquean archivos actuales de credenciales y patrones de alta confianza. El refuerzo de extensiones se encuentra en PR #158, **independiente**.
- `audit_public_git_history.py` revisa el historial Git alcanzable y solo informa **conteos y categorías**, sin publicar secretos, rutas ni hashes. Señales históricas **no** se clasifican ni se consideran resueltas automáticamente.
- Gitleaks CLI de código abierto (imagen `v8.30.1` inmovilizada por SHA-256) revisa los commits incorporados por un PR. Su salida completa se elimina sin imprimir ni subir archivos; todo error o detección causa fallo cerrado. El análisis histórico completo sigue siendo la auditoría separada.
- `composer validate` y `composer audit --locked` detectan inconsistencias del lock y advisories sin exclusiones ni silenciamiento. CI Laravel integral sigue existiendo.

## Auditoría privada y releases

La revisión de contenidos de GitHub Actions artifacts/releases **no debe descargarse ni publicarse** en workflows ejecutados desde PRs no confiables. La automatización actual de **solo metadatos** (`security-actions-artifact-metadata.yml`) permanece separada con permisos mínimos. La inspección del contenido sensible, la comparación con secretos activos y las rotaciones requieren operador autorizado y canal privado.

No crear automáticamente SARIF, comentarios con hallazgos, adjuntos ni artifacts que incluyan valores sensibles. Un CI verde **no certifica seguridad** y nunca cierra #102, #92 ni habilita release a main/AWS.

## Validación y despliegue

Revisar los tres jobs de `P0-102 Security & Composer PR gates (redacted)` en el SHA exacto del candidato. El administrador puede convertir el job de Gitleaks en check obligatorio una vez validado en ramas protegidas. No se puede dar por realizado ese cambio de reglas con la creación del workflow.

Esta mejora no altera el gate `release-readiness`, los flujos OIDC AWS, ni crea entorno UAT o aplica despliegues. Sigue **NO-GO** hasta evidencia real y aprobación humana.
