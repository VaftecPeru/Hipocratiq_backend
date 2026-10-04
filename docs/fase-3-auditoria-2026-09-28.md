# HIPOCRATIQ — Auditoría técnica Fase 3

Fecha: 2026-09-28  
Rama de trabajo: `develop`  
Estado de `main`: no modificado por esta fase.

## Alcance auditado

Fase 3 se revisó como capa de integraciones externas y operación clínica asociada:

- Telemedicina.
- IA epidemiológica.
- Recordatorios WhatsApp.
- Respaldos PostgreSQL.
- Facturación y pagos.
- Coherencia UX/UI contra el prototipo Figma `Version web`.

Referencias Figma revisadas:

- Telemedicina médico: `466:27698`.
- IA Epidemiológica médico: `466:29554`.
- Respaldos Superadministrador: `466:11351`.
- Facturación Superadministrador: `466:5297`.

## Hallazgos y mantenimiento aplicado

### 1. Telemedicina

Hallazgo:
la plataforma permitía programar y abrir sesiones sin comprobar el consentimiento clínico versionado de telemedicina.

Corrección:
- Backend exige consentimiento activo con el alcance `telemedicine`.
- Se vuelve a comprobar el consentimiento al pasar a estados `waiting` e `in_progress`.
- La Web informa al médico que el consentimiento es requisito del flujo.
- La cabecera, métricas y acciones se alinearon con la jerarquía del Figma.
- La métrica “Teleconsultas hoy” ahora se calcula sobre las sesiones del día y no sobre el total del rango.

### 2. IA epidemiológica

Hallazgos:
- el endpoint de clínica ya protegía la salida externa por consentimiento, pero el endpoint del médico no aplicaba el mismo criterio;
- el Frontend sólo tipaba `stable | not_configured`, aunque el analizador permite `rising | falling | mixed`;
- el analizador dependía de instrucciones de texto para producir JSON.

Correcciones:
- El endpoint médico construye una cohorte con consentimiento activo `epidemiology_anonymized`.
- Si la cohorte no alcanza el mínimo de privacidad configurado, no se llama al proveedor externo.
- La respuesta expone `analysis_cohort` con estado, versión de política, pacientes consentidos y mínimo requerido.
- Web muestra privacidad de cohorte, tendencias reales, alertas y recomendaciones.
- El adaptador OpenAI soporta Structured Outputs mediante `text.format/json_schema` estricto, controlado por `EPIDEMIOLOGICAL_AI_STRUCTURED_OUTPUT`.
- Se añadió prueba del contrato estructurado del adaptador.

### 3. WhatsApp

Hallazgo:
el comando de recordatorios seguía usando el booleano histórico `whatsapp_consent` del perfil.

Corrección:
- Se migró la autorización operacional al consentimiento versionado `whatsapp_notifications`.
- Se valida política vigente, estado granted, fecha efectiva y ausencia de revocación.
- Se añadió prueba de regresión:
  - sin consentimiento: no envía ni crea entrega;
  - con consentimiento: envía y registra `appointment_notification_deliveries`.

### 4. Respaldos

Hallazgos:
- `BACKUP_SCHEDULE` era visible en configuración, pero el scheduler ignoraba su valor y ejecutaba siempre a las 02:00;
- Infraestructura anunciaba `download` y `restore` como disponibles aunque la Web no exponía esas operaciones de forma segura;
- Figma sugería capacidades que podían interpretarse como inmediatamente ejecutables.

Correcciones:
- El scheduler respeta `daily@HH:MM` o una expresión cron configurable.
- Se añadieron endpoints SuperAdmin para:
  - crear respaldo;
  - verificar SHA-256.
- Ambas operaciones generan eventos de auditoría.
- Web incorpora “Crear respaldo ahora” y “Verificar respaldo pendiente”, con estados de carga, éxito y error.
- `download` y `restore` se reportan como no disponibles en Web.
- La restauración permanece `cli_only` hasta implementar un flujo reforzado de doble confirmación/2FA.

### 5. Facturación

Hallazgo:
el reembolso manual validaba el estado antes de actualizar sin bloqueo transaccional.

Corrección:
- El reembolso ahora usa `DB::transaction()` + `lockForUpdate()`.
- El estado se vuelve a validar dentro de la transacción.
- Se evita que solicitudes simultáneas procesen dos veces el mismo reembolso.

## Criterio UX/UI aplicado

Figma se mantiene como referencia de identidad visual, jerarquía, densidad y composición. No se copia literalmente una interacción cuando contradice el estado real del Backend.

Reglas aplicadas:

1. Una acción visual sólo se habilita si existe una capacidad real.
2. Estados `loading/error/empty/success/not_configured` deben ser visibles.
3. Operaciones clínicas sensibles muestran el requisito de consentimiento.
4. Operaciones destructivas o de infraestructura no se presentan como acciones simples.
5. Backend conserva la fuente de verdad; Web y Mobile consumen contratos.

## Estado CI

Al cierre de esta auditoría, los últimos workflows de GitHub Actions fallan antes de ejecutar pasos:

- Backend CI run `36449869327`.
- Frontend CI run `36449463358`.

En ambos casos GitHub reporta jobs con:

- `conclusion=failure`
- `steps=null`
- `logs_url=null`

Por lo tanto, no existe evidencia de fallo de Pint, PHPUnit, TypeScript, ESLint, Build o E2E en esos runs; el runner no llega a iniciar los steps. Este punto es un bloqueo de infraestructura/Actions y debe resolverse antes del release.

## Gate de Fase 3

Código e implementación: avanzada y mantenida en `develop`.

Release a `main`: BLOQUEADO hasta:

1. Recuperar ejecución normal de GitHub Actions.
2. Obtener Backend CI verde.
3. Obtener Frontend CI + E2E verdes.
4. Obtener Mobile CI Android/iOS verde.
5. Ejecutar UAT de Telemedicina, consentimientos, WhatsApp, IA, backups y facturación con configuración de staging/producción.
6. Revisar secretos/variables de entorno sin exponer credenciales.
7. Solicitar autorización explícita antes de fusionar `develop` a `main`.

## Resultado

La Fase 3 deja de ser un conjunto de pantallas aisladas y queda conectada a reglas operacionales del Backend. El siguiente trabajo no debe ampliar alcance hasta recuperar CI y ejecutar UAT con evidencia.
