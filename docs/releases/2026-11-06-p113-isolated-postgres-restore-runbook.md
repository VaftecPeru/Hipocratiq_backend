# HIPOCRATIQ #113 — Runbook de recuperación PostgreSQL aislada

Fecha: 2026-11-06. Documento preparatorio. **No acredita ejecución real.**

## Alcance y condiciones obligatorias

- No operar sobre producción ni exportar PHI a GitHub Actions o artifacts.
- La prueba sintética de cifrado/restauración está integrada en `develop` por PR #175; no acredita respaldo real.
- Requiere entorno UAT independiente con controles de red (sin rutas a la BD productiva), cuenta de acceso mínimo, PostgreSQL compatible, disco privado cifrado y política aprobada de retención y destrucción.
- Conjunto de datos **sintéticos o desidentificados autorizados**. No tomar dump de la base clínica productiva sin autorización nueva y específica.
- Registrar en un canal de evidencias privado: responsable, cuenta/entorno y SHA de código; nunca contraseñas, datos clínicos, tokens, claves KMS o identificadores sensibles en issues públicos.
- Acceso AWS / creación de recursos / costos / operaciones productivas requieren su respectiva autorización; este documento no la otorga.

## Preparación — checklist bloqueante

- [ ] Responsable clínico/seguridad aprueba la procedencia, nivel de sensibilidad y uso de datos.
- [ ] Administrador valida separación del entorno UAT, región/cuenta y redes mediante acceso de solo lectura autorizado.
- [ ] Destino privado con cifrado en reposo y tránsito; credenciales entregadas por gestor de secretos a operador autorizado, no variables impresas.
- [ ] Duración de retención y borrado seguro aprobados; responsable de custodia definido.
- [ ] Plan de rollback documentado; no existe conexión ni operación a la base productiva.
- [ ] Ventana y registro de cambio autorizados para la prueba aislada.
- [ ] Se comprueba disponibilidad del espacio necesario, versión PostgreSQL compatible y procedimientos de limpieza.
- [ ] Se vincula la evidencia de CI **posterior al merge** de PR #175 al SHA final de `develop` (SUCCESS real, no skipped). La consulta de runs disparados por PR no sustituye `push` de develop.

## Ejecución exclusiva en entorno no productivo

1. Confirmar identidad del operador, entorno aislado y base de datos de ensayo. Registrar marca temporal y fecha.
2. Generar respaldo lógico consistente del conjunto autorizado **en el entorno no productivo**. No mostrar datos en logs.
3. Cifrar el respaldo usando un mecanismo aprobado de la organización y gestión de claves separada; denegar acceso público.
4. Calcular y registrar SHA-256 del respaldo cifrado y del contenido restaurable en evidencia privada, junto con tamaño/fecha; no subir el respaldo a GitHub.
5. Restaurar en **otra base UAT nueva** y comprobar salida sin errores de `pg_restore`. No usar `--clean` ni sobrescribir bases existentes o producción.
6. Validar esquema, extensiones, objetos y conteos **agregados no sensibles**, consistencia referencial y pruebas funcionales básicas de Laravel. No divulgar filas.
7. Ejecutar simulación de rollback **solo de aplicación UAT** y anotar RTO/RPO observados; las mediciones no representan automáticamente los objetivos contractuales.
8. Revisar integridad, cifrado, permisos, logs saneados y evidencia con una segunda persona; limpiar archivos/DB temporales según retención aprobada.

## Criterios GO para cerrar #113

- Respaldo cifrado realmente generado de datos autorizados, con ubicación privada y checksum verificado.
- Restauración aislada real a PostgreSQL, con comprobación funcional e integridad aprobada.
- Política de retención, custodia, borrado, rollback y tiempos observados documentados.
- Evidencias sanitizadas, SHA exacto, fecha y aprobación independiente del operador/cliente responsable.
- No se exponen PHI ni secretos; no se modifican AWS ni producción.

Si falta un criterio, mantener #113 **OPEN / NO-GO** y `PRODUCTION_RESTORE_CERTIFIED=false`.

## Siguiente bloqueo operativo

El runbook puede integrarse a `develop` tras revisión. **No ejecutar un backup de la base clínica de producción** hasta que exista autorización concreta para usar los datos, destino cifrado y entorno aislado con acceso validado.
