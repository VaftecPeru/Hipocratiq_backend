# HIPOCRATIQ — Acta de aceptación clínica WEB (#92)

**Estado: BORRADOR / NO FIRMADA / NO APROBADA.** No se autoriza el release con este documento por sí solo.

## Identificación de la entrega
- ID del acta / cambio: **PENDIENTE**
- Fecha y hora de ejecución UTC: **PENDIENTE**
- Backend SHA exacto: **PENDIENTE DE CONGELAR**
- Frontend SHA exacto: **PENDIENTE DE CONGELAR**
- Ambiente/URL de validación, aislado y autorizado: **PENDIENTE**
- Responsable clínico (nombre, cargo): **PENDIENTE**
- Responsable de seguridad (nombre, cargo): **PENDIENTE**
- Ejecutor QA (nombre, cargo): **PENDIENTE**
- Alcance: **web y API**, no autoriza lanzamiento móvil.

## Precondiciones obligatorias
- Cuentas, clínicas, usuarios, pacientes y documentos exclusivamente **sintéticos**.
- No exponer PHI, credenciales, OTP reales, tokens, capturas identificables ni URLs privadas en este repositorio público.
- Evidencias completas custodiadas en un sistema **privado** aprobado, referenciadas aquí solo mediante identificadores no sensibles.
- No probar sobre pacientes reales ni interrumpir producción. Registrar la versión exacta de pruebas.
- Cualquier FAIL P0/P1 bloquea la firma hasta corrección y retest.

## Resultados por escenario
**Instrucción:** sustituir PENDIENTE únicamente con el resultado auténtico y su referencia privada verificable.

| ID | Escenario | Resultado | Referencia privada (ID no sensible) | Defecto/retest |
|---|---|---|---|---|
| C01 | Autenticación válida e inválida, sesión | PENDIENTE | PENDIENTE | PENDIENTE |
| C02 | OTP expiración, intentos y bloqueo | PENDIENTE | PENDIENTE | PENDIENTE |
| C03 | Acceso entre clínicas/tenants denegado | PENDIENTE | PENDIENTE | PENDIENTE |
| C04 | RBAC y acceso médico no autorizado | PENDIENTE | PENDIENTE | PENDIENTE |
| C05 | Paciente solo ve su expediente autorizado | PENDIENTE | PENDIENTE | PENDIENTE |
| C06 | Recepción no altera historia clínica | PENDIENTE | PENDIENTE | PENDIENTE |
| C07 | Registro, firma e integridad de historia | PENDIENTE | PENDIENTE | PENDIENTE |
| C08 | Adjuntos y resultados seguros | PENDIENTE | PENDIENTE | PENDIENTE |
| C09 | Fallas transaccionales y concurrencia | PENDIENTE | PENDIENTE | PENDIENTE |
| C10 | Cierre/revocación de sesión | PENDIENTE | PENDIENTE | PENDIENTE |
| C11 | Navegación, UX y accesibilidad web | PENDIENTE | PENDIENTE | PENDIENTE |
| C12 | Auditoría por actor/tenant | PENDIENTE | PENDIENTE | PENDIENTE |

## Resumen de ejecución (no completar por inferencia)
- PASS: **PENDIENTE** / 12
- FAIL: **PENDIENTE** / 12
- NO EJECUTADO: **12** / 12 (estado inicial de esta acta)
- Defectos P0/P1 abiertos al cierre: **PENDIENTE**
- Informe de QA y ubicación privada: **PENDIENTE**
- Riesgos residuales aceptados formalmente: **PENDIENTE**

## Decisión clínica y de seguridad
- Resultado clínico: **NO EMITIDO** (APROBADO / RECHAZADO cuando corresponda)
- Responsable clínico, firma autenticada, fecha UTC y referencia privada del acta: **PENDIENTE**
- Resultado de seguridad: **NO EMITIDO**
- Responsable de seguridad, firma autenticada, fecha UTC y referencia privada: **PENDIENTE**
- Aprobación del cambio por responsable de release: **NO EMITIDA**
- Referencia al control de auditoría integral #102: **ABIERTA**
- Estado del respaldo/restauración #113: **DIFERIDO, NO VERIFICADO**

**Criterio de cierre:** exigir ejecución verificable 12/12, cero defectos bloqueantes, referencias privadas auditables y firmas auténticas vinculadas a los SHA exactos. Hasta entonces #92 permanece abierta y el release es **NO-GO**.

Referencias: `docs/releases/clinical-92-web-acceptance-matrix.md`, `docs/releases/clinical-92-existing-tests-crosswalk.md`.
