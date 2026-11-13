# HIPOCRATIQ — Auditoría integral de seguridad #102
Estado: **PENDIENTE DE EVIDENCIA**. No registrar secretos ni PHI en GitHub.

## Alcance mínimo
- Confirmar con el propietario la política de repositorios públicos y los contratos aplicables.
- Revisar **todas** las ramas, tags e historial Git de Backend y Frontend (incluidos commits borrados que sigan accesibles, cuando proceda).
- Revisar releases, packages, logs de Actions, artefactos descargables, archivos de configuración y variables versionadas.
- Identificar y rotar toda credencial comprometida; revocar tokens antiguos y verificar su invalidación.
- Revisar permisos GitHub, secretos de Actions, reglas de protección, CODEOWNERS y OIDC trust de producción.
- Escanear dependencias, contenedores y configuración para exposiciones relevantes.
- Emitir informe **privado** con hashes, alcance, hallazgos y remediaciones; referenciar aquí solamente ID de acta no sensible.

## Criterios de cierre
1. Dueño de seguridad identificado, fecha UTC y referencia privada del informe.
2. Todos los hallazgos críticos y altos corregidos o riesgo explícitamente aceptado por autoridad competente según política.
3. Secretos expuestos revocados/rotados y validados; revisión histórica documentada.
4. Visibilidad pública confirmada conforme al marco contractual; nunca asumir que CI metadata equivale a auditoría completa.
5. Responsable aprueba por escrito y vincula el informe al SHA Backend y Frontend.

Sin informe verificable y aprobación nominativa: **NO-GO**.
