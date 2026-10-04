# HIPOCRATIQ — Auditoría Fullstack Clínica y Plan de Cierre

**Fecha:** 2026-09-28  
**Rama auditada:** `develop`  
**Rama de producción:** `main` (sin modificar)  
**Alcance:** Backend Laravel, PostgreSQL, Web Next/React, Mobile Flutter, seguridad, interoperabilidad, UX/UI y DevSecOps.

> Este documento describe alineación técnica y preparación del producto. No constituye certificación HIMSS, ISO, NOM, HL7, SNOMED CT ni regulatoria.

## 1. Marco de arquitectura utilizado

HIPOCRATIQ se desarrolla con el siguiente marco:

`BPMN clínico → dominio asistencial → Laravel Modular Monolith → PostgreSQL → API REST/FHIR-ready → Web/Flutter → auditoría/seguridad → CI/CD`.

Referencias de producto:

- HIMSS EMRAM / O-EMRAM como mapa de madurez digital.
- BPMN 2.0 para continuidad asistencial.
- HL7 FHIR R5 como contrato de interoperabilidad.
- SNOMED CT / ICD-11 como estrategia de codificación estructurada.
- DICOM/PACS como arquitectura objetivo para imagenología.
- ISO/IEC 27001 + ISO 27799 como marco de controles de seguridad.
- NOM-004-SSA3 y NOM-024-SSA3 como referencias regulatorias para México.
- Scrum + DevSecOps para entrega incremental.

## 2. Flujo clínico auditado

Flujo principal:

`Paciente → Cita → Recepción → Médico → Consulta/Encounter → Historia clínica → Diagnóstico codificado → Receta/Orden → Firma → Portal paciente → Seguimiento`.

Controles implementados:

- una consulta por cita;
- sincronización entre cita y consulta;
- edición parcial sin pérdida de campos no incluidos;
- consultas firmadas/canceladas inmutables;
- firma con huella SHA-256;
- terminología clínica incluida dentro de la huella firmada;
- cambio real de actor Admin → Médico → Paciente cubierto por UAT;
- aislamiento de acceso por paciente, médico, clínica y superadministrador.

## 3. Interoperabilidad

### FHIR R5

Se agregó una fachada de lectura con `CapabilityStatement` y recursos:

- `Patient`
- `Practitioner`
- `Organization`
- `Appointment`
- `Encounter`
- `Condition`
- `MedicationRequest`
- `ServiceRequest`
- `DiagnosticReport`
- `Consent`

La fachada se mantiene read-only deliberadamente para no introducir escritura FHIR sin validación terminológica ni perfiles de implementación definidos.

### Terminología clínica

La consulta soporta conceptos estructurados con:

- categoría;
- sistema;
- código;
- descripción;
- estado;
- médico registrador;
- paciente;
- consulta;
- fecha.

Sistemas preparados en cliente Web:

- SNOMED CT: `http://snomed.info/sct`
- ICD-11/CIE-11: `http://id.who.int/icd/release/11/mms`
- catálogo local HIPOCRATIQ.

La validación contra terminology server queda desacoplada; no se simula validación externa.

## 4. Seguridad y trazabilidad

Implementado:

- RBAC/contexto de actor;
- sesión Web y token Mobile;
- OTP;
- separación de roles;
- control de clínica;
- auditoría administrativa;
- auditoría clínica dedicada;
- snapshots clínicos anteriores/posteriores cifrados;
- actor, IP, user-agent y request ID;
- consultas firmadas inmutables;
- consentimiento clínico versionado;
- revocación trazable;
- `composer audit`;
- `npm audit --audit-level=high`;
- contenedores y migraciones PostgreSQL en CI.

Consentimientos soportados:

- atención clínica;
- tratamiento de datos;
- notificaciones WhatsApp;
- telemedicina;
- asistencia clínica con IA;
- epidemiología anonimizada.

## 5. Web

Estado funcional:

- autenticación por rol;
- OTP;
- rutas seguras por actor;
- paneles por rol;
- consulta médica;
- expediente clínico;
- codificación estructurada;
- portal del paciente;
- consentimientos;
- laboratorio;
- recetas;
- triaje;
- aislamiento de contexto.

Mantenimiento UX/UI aplicado:

- Login 40/60 según prototipo;
- visual clínico lateral de mayor jerarquía;
- tarjeta de seguridad superpuesta;
- consultas médicas con jerarquía de Figma;
- paciente actual y evolución contextual;
- historial del paciente como línea de tiempo;
- métricas, filtros y pestañas;
- dashboard paciente alineado al prototipo;
- corrección de marca MEDIQ → HIPOCRATIQ.

## 6. Mobile Flutter

Implementado:

- login/OTP;
- registro;
- recuperación de contraseña;
- sesión segura por dispositivo;
- dashboard por actor;
- paciente, médico, recepción y administrador;
- actividad;
- cuenta;
- logout local/global;
- consentimiento clínico del paciente;
- Android e iOS compartiendo el mismo Backend.

Artefactos CI:

- Android APK de validación;
- Android AAB de validación;
- iOS `.app` release sin firma.

La firma de producción para Google Play y App Store/TestFlight requiere credenciales externas de tienda y no debe confundirse con la validación técnica del binario.

## 7. DICOM/PACS

No se ha implementado almacenamiento DICOM dentro del core. Esto es intencional.

Arquitectura objetivo:

`modalidad DICOM → PACS/VNA → visor web → referencia en expediente/FHIR`.

No se recomienda guardar estudios radiológicos como simples JPG adjuntos.

## 8. DevSecOps

Gates configurados en `develop`:

### Backend
- Composer install/audit;
- Laravel Pint;
- suite PHPUnit;
- migraciones PostgreSQL;
- performance smoke;
- Docker Compose config;
- Docker build;
- pg_dump / pg_restore.

### Frontend
- npm audit HIGH+;
- TypeScript;
- pruebas de regresión;
- ESLint;
- Next build;
- Docker build;
- Playwright E2E por rol.

### Mobile
- dart format;
- flutter analyze;
- flutter test;
- Android APK;
- Android AAB;
- iOS release no-codesign;
- publicación de artefactos de validación.

## 9. Brechas que no deben marcarse como completas sin evidencia

- certificación HIMSS;
- certificación ISO;
- certificación NOM;
- validación oficial SNOMED/ICD contra terminology server;
- PACS/DICOM productivo;
- firma Apple/Google Play de producción;
- pruebas clínicas formales por una institución sanitaria;
- pentest externo;
- DR/BCP probado fuera de CI.

## 10. Criterio para merge a main

No se autoriza técnicamente un merge por tener funcionalidades implementadas.

El candidato debe cumplir simultáneamente:

1. Backend CI verde.
2. Frontend CI + E2E verde.
3. Mobile Android/iOS verde.
4. Migraciones verificadas.
5. Sin secretos en repositorio.
6. UAT clínico estable.
7. Auditoría final actualizada.
8. Autorización explícita del titular para merge a `main`.

Hasta entonces, `main` permanece congelada.
