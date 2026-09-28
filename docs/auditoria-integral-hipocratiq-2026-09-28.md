# Auditoría integral HIPOCRATIQ — 28/09/2026

## 1. Alcance

Auditoría técnica, clínica, de seguridad, DevSecOps e interoperabilidad sobre la rama `develop` de HIPOCRATIQ.

**Regla de release:** `main` permanece fuera de alcance hasta autorización expresa y hasta que Backend, Web y Mobile tengan gates verdes.

## 2. Estado ejecutivo

**Estado actual: NO LIBERABLE A PRODUCCIÓN.**

La plataforma tiene una base funcional y arquitectónica sólida, pero los cambios más recientes de política dinámica de consentimiento e IA epidemiológica dejaron regresiones de CI en `develop`. Estas regresiones son P0 y bloquean cualquier propuesta de merge a `main`.

Últimos gates verdes conocidos antes de esa regresión:
- Backend: `bf07e7df95488feaebc77aed01a632b8ab719ec6`.
- Web: `c14e13af5163fc4b4960899baf76bb6d92050524`.
- Mobile: `85789a5ea0a51bdd2e5c4a6fdaa1692155a69bd7`.

## 3. Arquitectura actual

### Backend
- Laravel.
- PostgreSQL.
- API REST versionada.
- Arquitectura modular/monolito modular con separación por dominio funcional.
- Sanctum / sesión first-party y token móvil.
- Auditoría administrativa + auditoría clínica cifrada.
- Firma de integridad SHA-256 para consultas firmadas.

### Web
- React / Next.js.
- TypeScript.
- Sistema de tokens CSS y componentes por rol.
- Flujos separados para Superadmin, clínica/recepción, médico y paciente.
- Pruebas TypeScript, unitarias/regresión y E2E.

### Mobile
- Flutter.
- Android + iOS.
- Misma API Laravel como fuente de verdad.
- Sesión segura por dispositivo.
- Workspace adaptado por rol.
- Pipeline de APK/AAB Android y build iOS sin firma.

## 4. Flujo clínico

Flujo implementado:

Paciente → Cita → Recepción/Triaje → Consulta → Historia clínica → Codificación clínica → Diagnóstico/órdenes/receta → Completado → Firma → Portal paciente.

Controles relevantes:
- Una consulta por cita.
- Cita y consulta sincronizan estado.
- PATCH parcial conserva información no modificada.
- Una consulta firmada o cancelada queda inmutable.
- La firma incorpora datos clínicos estructurados a la huella SHA-256.
- El paciente solo accede a sus propios datos.
- El médico requiere vínculo clínico con el paciente.

## 5. Interoperabilidad

### FHIR R5
Se implementó una fachada read-only con:
- CapabilityStatement.
- Patient.
- Practitioner.
- Organization.
- Appointment.
- Encounter.
- Condition.
- MedicationRequest.
- ServiceRequest.
- DiagnosticReport.
- Consent.

**Estado:** FHIR-ready/read-only. No debe presentarse todavía como servidor FHIR completo ni como implementación certificada.

### Terminología
Modelo estructurado:
- categoría;
- sistema terminológico;
- código;
- descripción;
- estado;
- consulta/paciente/médico asociado.

Preparado para SNOMED CT e ICD/CIE.

**Pendiente:** terminology server y validación formal de códigos/licencias.

### DICOM/PACS
No implementado todavía. Debe mantenerse como integración desacoplada:
DICOM → PACS/VNA → visor → referencia desde expediente.

No se recomienda almacenar estudios diagnósticos simplemente como JPG en la ficha clínica.

## 6. Consentimiento y privacidad

Implementado:
- consentimiento versionado;
- otorgamiento/revocación;
- trazabilidad;
- scopes separados:
  - clinical_care;
  - data_processing;
  - whatsapp_notifications;
  - telemedicine;
  - ai_clinical_assistance;
  - epidemiology_anonymized.

Última evolución:
- Backend expone versión vigente de política.
- Web/Mobile dejaron de depender de una versión hardcodeada.
- IA epidemiológica limita el análisis externo a cohortes con consentimiento activo y un mínimo configurable de privacidad.

**P0 actual:** esta evolución debe volver a quedar verde en CI antes de considerarse cerrada.

## 7. Auditoría clínica

Existe `clinical_audit_events` con:
- actor;
- clínica;
- paciente;
- consulta;
- acción;
- IP;
- user-agent;
- request-id;
- snapshot anterior;
- snapshot posterior;
- fecha.

Los snapshots clínicos sensibles se almacenan cifrados.

Esto evita modificaciones silenciosas y permite reconstrucción de trazabilidad.

## 8. IA epidemiológica

Diseño actual:
- métricas generales internas permanecen disponibles sin IA externa;
- el proveedor externo recibe solo un snapshot agregado;
- se filtra por consentimiento epidemiológico activo;
- se exige cohorte mínima configurable;
- si el proveedor falla, el sistema degrada a datos internos;
- las señales se presentan como apoyo operativo y no como diagnóstico.

**Pendiente de cierre:** reparar CI de los últimos cambios y ejecutar UAT de privacidad/consentimiento.

## 9. Seguridad

Fortalezas:
- RBAC y contexto de actor.
- MFA/OTP.
- sesiones por dispositivo.
- TLS exigido en Mobile release.
- auditoría.
- registros firmados e inmutables.
- secretos fuera del repositorio.
- `composer audit` y `npm audit` en pipeline.
- migraciones PostgreSQL verificadas.
- backup tooling validado en imagen Docker.

Brechas:
- `develop` no está protegido actualmente por reglas/status checks obligatorios.
- La política de protección de `main` debe verificarse antes del release.
- Falta completar un paquete de evidencia formal de recuperación/restore en ambiente preproductivo.

## 10. UX/UI

Fortalezas:
- design tokens consistentes: superficies, texto, accent, estados semánticos, focus y dark mode.
- navegación por rol.
- estados de foco visibles.
- soporte `prefers-reduced-motion`.
- componentes responsivos.
- mejoras recientes alineadas con el prototipo: login, dashboard paciente, timeline clínico y shell médico.
- Flutter comparte la línea visual principal.

Hallazgo:
Los shells Superadmin, Clínica, Médico y Paciente todavía presentan diferencias de geometría y densidad (sidebar, header, radios, spacing y tamaños de interacción). Parte es intencional por contexto de uso, pero falta consolidar un sistema común de layout para evitar divergencia progresiva.

El Figma exacto no está disponible en este contexto mediante un `fileKey + node-id`, por lo que la verificación píxel-a-píxel queda pendiente. La implementación continuará usando como fuente de verdad los tokens/componentes ya derivados del prototipo en el repositorio.

## 11. DevSecOps / CI-CD

Gates esperados:

### Backend
1. Composer install.
2. Composer audit.
3. Pint.
4. Suite PHPUnit.
5. PostgreSQL migrations.
6. Performance smoke.
7. Docker Compose validation.
8. Docker image build.
9. pg_dump/pg_restore.

### Web
1. npm audit.
2. TypeScript.
3. pruebas de regresión.
4. ESLint.
5. Next build.
6. Docker build.
7. Playwright E2E.

### Mobile
1. dart format.
2. flutter analyze.
3. flutter test.
4. launcher assets.
5. Android APK.
6. Android AAB.
7. iOS no-codesign build.
8. artifacts de validación.

**P0:** los tres stacks deben estar verdes en el mismo estado de `develop` antes del release candidate.

## 12. Alineación con Clinical Digital Framework

### HIMSS EMRAM / O-EMRAM
Se utiliza como mapa de evolución de madurez, no como certificación.

### BPMN
El desarrollo se organiza alrededor de continuidad asistencial y estados clínicos, no de pantallas aisladas.

### HL7 FHIR
Fachada R5 read-only implementada.

### SNOMED CT / ICD
Modelo de codificación preparado; validación externa pendiente.

### ISO 27001 / ISO 27799
Se están aplicando principios de mínimo privilegio, trazabilidad, cifrado, sesiones, auditoría y continuidad.

### México
La solución debe mantener revisión jurídica/técnica vigente respecto a NOM-004 y NOM-024 antes de una declaración de conformidad regulatoria.

## 13. Backlog de cierre

### P0 — bloquea release
- Reparar CI Backend tras cambios IA/consentimiento.
- Reparar CI Web tras política dinámica.
- Reparar CI Android/iOS tras política dinámica.
- Ejecutar suite completa en heads finales.
- Confirmar que no existen migraciones rotas.
- Confirmar que `main` no fue alterada.

### P1 — calidad de release
- Consolidar geometría/tokens de shells.
- Añadir UAT explícito de consentimiento Web/Mobile.
- Añadir pruebas de API FHIR para recursos adicionales.
- Documentar rollback de migraciones y restore.
- Crear release checklist.

### P2 — evolución
- Terminology server.
- DICOM/PACS.
- firma de producción Android/iOS y publicación en stores.
- interoperabilidad FHIR de escritura cuando exista caso de uso y gobernanza.
- validación/certificación normativa externa.

## 14. Criterio de terminado

HIPOCRATIQ solo se considerará candidato a merge a `main` cuando:

- Backend verde.
- Web verde.
- Mobile Android verde.
- Mobile iOS verde.
- UAT clínico verde.
- migraciones PostgreSQL verdes.
- seguridad/dependency audits sin bloqueantes definidos.
- auditoría final actualizada.
- release checklist cerrado.
- ninguna deuda P0 abierta.

Hasta entonces, todo permanece en `develop`.
