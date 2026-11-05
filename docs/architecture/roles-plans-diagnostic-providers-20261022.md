# HIPOCRATIQ + IA — Matriz funcional, planes y afiliación diagnóstica (22/10/2026)

> Documento de diseño y criterios de aceptación; NO certifica funcionalidad completa ni sustituye aprobación contractual del cliente. Esta rama QA no afecta `develop`, `main` ni producción.

## 1. Arquitectura

- **Una API Laravel 13, PostgreSQL y Sanctum** para Web (Next/React) y Flutter (Android/iOS); no duplicar reglas clínicas en móviles.
- Aislar accesos por `actor_context`, organización/tenant, relación médico-paciente, afiliación por clínica y consentimiento.
- `clinic_id = null` **no implica** permiso para consultar datos de otros tenants.
- Datos sintéticos para test; trazabilidad de cambios, firmas de resultados, versiones de archivos, almacenamiento privado con URLs de duración limitada.
- Las reglas de facturación se aplican desde backend como *entitlements*, nunca sólo ocultando botones en UI.

## 2. Matriz de roles y pruebas obligatorias

| Perfil | Implementación comprobada en código | Acceso esperado | Restricciones y pendientes |
|---|---|---|---|
| Superadministrador SaaS | `RoleKey::SuperAdmin` | Gestión global de clientes/planes/infraestructura | Acceso a PHI por excepción auditada, no por defecto |
| Soporte VAFTEC | No rol dedicado comprobado | Incidencias técnicas y soporte administrativo | Crear rol/política sin lectura indiscriminada de historias clínicas |
| Administrador de clínica | `RoleKey::ClinicAdmin` | Gestión de su clínica, staff, agenda, membresía | Rechazar clínica ajena; cero privilegios superadmin |
| Médico de clínica | `RoleKey::Doctor`, `DoctorPracticeType::Clinic` | Sus pacientes asignados, consulta, receta, órdenes | Clínica asignada y políticas; evitar IDOR entre clínicas |
| Médico independiente | `RoleKey::Doctor`, `DoctorPracticeType::Independent`, `DoctorSubscription` | Consulta privada, pacientes propios, plan individual | Ninguna búsqueda clínica global; cuota por plan |
| Recepción | `RoleKey::Receptionist` | Agenda, alta administrativa, seguimiento de citas | No lectura/edición de diagnóstico ni receta por defecto |
| Paciente asociado a clínica | Contexto `patient`; `PatientProfile.clinic_id` | Perfil, citas, preconsulta, resultados autorizados, membresía | Sólo su expediente y consentimiento |
| Paciente independiente | Existe `PatientProfile` con `clinic_id` anulable, pero flujos de triaje requieren clínica | Perfil y plan personal, vincularse voluntariamente a médico/clínica | Diseñar `PatientSubscription` y workflow independiente; jamás eliminar validación tenant sin reemplazar con propiedad/consentimiento |
| Administrador laboratorio/gabinete | Nueva organización `DiagnosticProvider` en rama QA (solicitud PENDING) | Gestionar contrato/operadores y solicitudes aprobadas | Cuenta, OTP, verificación legal y suscripción pendientes |
| Técnico laboratorio / imagen | Membresía diagnóstica propuesta, sin login habilitado | Cargar resultados al pedido asignado | Sólo órdenes de clínicas explícitamente afiliadas; no expediente completo; supervisor valida |
| IA epidemiológica | Servicio IA previsto en alcance | Métricas agregadas/desidentificadas bajo contrato | Nunca introducir un usuario humano con acceso universal al expediente |

## 3. Suscripciones por audiencia

- **Clínicas:** `ClinicSubscription` + `Plan` existentes. Criterios: cupos, sede, usuarios, estados de cobro/renovación.
- **Médico independiente:** `DoctorSubscription` + `Plan` existentes; aplicar límites server-side.
- **Paciente independiente:** aún sin `PatientSubscription` verificado. Requiere ciclo personal (prueba, activa, suspendida, vencida), beneficios y consentimiento sin crear clínica ficticia.
- **Laboratorio o imagenología:** modalidad de organización independiente, NO reutilizar automáticamente `clinic_subscriptions` ni asignar rol médico. Sus precios, cupos de órdenes/operadores y modalidades requieren decisión comercial de Oscar.
- Nunca asumir los importes del prototipo como tarifa contractual vigente.

## 4. Registro y afiliación diagnóstica

1. Solicitud pública con contacto comercial y consentimiento, normalizada y limitada por tasa.
2. Estado inicial `pending`; el endpoint de solicitud NO crea `users`, `roles`, `clinic_memberships`, `tokens` o expedientes.
3. Verificación externa de personalidad jurídica/teléfono/email; aprobación por Superadmin habilitado y auditado.
4. Invitación de administrador/operador con OTP y principio del mínimo privilegio.
5. Una clínica solicita/acepta afiliación. La relación proveedor ↔ clínica es explícita y revocable.
6. Orden clínica ↔ paciente ↔ proveedor asignado, sin consulta arbitraria de pacientes ajenos.
7. Un técnico sólo carga estudio/informe/adjunto privado a su orden; un médico responsable revisa/valida; se registra autor, fecha, versión y cambios.
8. Proveedor NO afiliado: el médico puede adjuntar manualmente un PDF/imágenes y atribuir procedencia; nunca falsificar firma electrónica.
9. Baja/suspensión revoca tokens y accesos, conserva trazabilidad y cumple retención de documentos correspondiente.
10. Archivos: cuarentena, AV, MIME real, máximos de tamaño, almacenamiento privado, descarga con autorización por orden y tenant.

### Estado de esta rama

**Implementado como base, no liberado**: tablas de organización/membresía/afiliación y solicitud con estado `pending`. **NO implementado aún**: aprobación y revisión, OTP de operadores, planes del proveedor, permisos/portal, conexión de resultados clínicos y pruebas UAT.

## 5. Gates de QA y autorización

- Backend: migraciones PostgreSQL en UAT, revisión de índices/constraints, tests de registro/validación, PHPUnit total, multi-tenant e IDOR; PHP 8.4.
- Web: pantallas funcionales por rol, pruebas Playwright, responsive, accesibilidad, contratos backend.
- Flutter Android/iOS: `flutter analyze`, `flutter test`, builds firmados sólo con backend UAT; pruebas de instalación, biometría/OTP y errores.
- API AWS: mocks primero; SMS reales únicamente con cuenta/número MX +52 controlados, IAM y origination identity autorizados.
- No promover a `develop` hasta pasar gates de la feature y recibir aprobación expresa solicitada; `main` requiere gate de release posterior, respaldo y nueva autorización.
