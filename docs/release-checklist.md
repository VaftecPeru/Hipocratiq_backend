# HIPOCRATIQ — Release Checklist

Este checklist es obligatorio antes de solicitar merge de `develop` a `main`.

## A. Control de versión
- [ ] `develop` contiene únicamente cambios auditados.
- [ ] `main` no fue modificada durante el ciclo de desarrollo.
- [ ] No existen conflictos `main...develop`.
- [ ] Se identifica SHA exacto de Backend.
- [ ] Se identifica SHA exacto de Frontend/Mobile.
- [ ] Auditoría integral actualizada.

## B. Backend
- [ ] `composer validate`.
- [ ] `composer audit --locked`.
- [ ] Laravel Pint.
- [ ] PHPUnit completo.
- [ ] UAT clínico completo.
- [ ] Migraciones desde base limpia.
- [ ] Migraciones sobre copia preproductiva.
- [ ] `route:list` válido.
- [ ] Docker Compose válido.
- [ ] Imagen Backend construida.
- [ ] `pg_dump` disponible.
- [ ] `pg_restore` disponible.
- [ ] Backup previo al deploy.
- [ ] Restore ensayado.

## C. Flujo clínico
- [ ] Crear/agendar cita.
- [ ] Recepción visualiza cita.
- [ ] Médico abre paciente correcto.
- [ ] Médico inicia consulta.
- [ ] Una cita no genera dos consultas.
- [ ] Historia clínica guarda secciones.
- [ ] Terminología clínica guarda system/code/display.
- [ ] Estado in_progress → completed.
- [ ] Estado completed → signed.
- [ ] Firma genera SHA-256.
- [ ] Consulta firmada queda inmutable.
- [ ] Paciente visualiza consulta firmada.
- [ ] Auditoría clínica before/after registrada y cifrada.

## D. Consentimiento
- [ ] Backend expone versión vigente.
- [ ] Web consume versión vigente.
- [ ] Mobile consume versión vigente.
- [ ] Alta de consentimiento.
- [ ] Revocación.
- [ ] Idempotencia.
- [ ] Auditoría de otorgamiento/revocación.
- [ ] FHIR Consent.
- [ ] IA epidemiológica usa únicamente cohortes consentidas.
- [ ] Cohorte mínima de privacidad bloquea análisis externo.
- [ ] No se envían identificadores personales al motor epidemiológico.

## E. FHIR
- [ ] CapabilityStatement R5.
- [ ] Patient.
- [ ] Practitioner.
- [ ] Organization.
- [ ] Appointment.
- [ ] Encounter.
- [ ] Condition.
- [ ] MedicationRequest.
- [ ] ServiceRequest.
- [ ] DiagnosticReport.
- [ ] Consent.
- [ ] Control de acceso por recurso.
- [ ] Content-Type FHIR JSON.

## F. Web
- [ ] `npm audit --omit=dev --audit-level=high`.
- [ ] TypeScript.
- [ ] pruebas de regresión.
- [ ] ESLint.
- [ ] Next build.
- [ ] Docker build.
- [ ] Playwright E2E.
- [ ] Superadmin.
- [ ] Administrador clínica.
- [ ] Recepción.
- [ ] Médico.
- [ ] Médico independiente.
- [ ] Paciente.
- [ ] Responsive móvil/tablet/escritorio.
- [ ] Estados loading/empty/error/success.

## G. UX/UI y accesibilidad
- [ ] Línea visual HIPOCRATIQ consistente.
- [ ] Branding correcto por rol.
- [ ] Focus visible.
- [ ] Menús móviles con Escape/focus trap.
- [ ] Touch targets adecuados.
- [ ] Mensajes no dependen solo de color.
- [ ] Consentimiento anuncia cambios.
- [ ] Consulta firmada comunica claramente modo solo lectura.
- [ ] Zoom/text scale probado.
- [ ] Revisión final contra Figma cuando exista node-id oficial.

## H. Mobile Android
- [ ] `dart format --output=none --set-exit-if-changed`.
- [ ] `flutter analyze`.
- [ ] `flutter test`.
- [ ] Iconos generados.
- [ ] APK validation.
- [ ] AAB validation.
- [ ] Endpoint HTTPS de producción.
- [ ] Identificador `com.vaftec.hipocratiq`.
- [ ] Firma release configurada antes de publicación Play Store.

## I. Mobile iOS
- [ ] Build iOS release `--no-codesign` de validación.
- [ ] Bundle identifier correcto.
- [ ] Assets/iconos.
- [ ] Validación en dispositivo/simulador según disponibilidad.
- [ ] Certificado + provisioning profile antes de IPA/TestFlight.
- [ ] TestFlight antes de App Store.

## J. Correo/OTP
- [ ] SMTP preflight.
- [ ] Registro clínica recibe OTP por correo.
- [ ] Registro médico recibe OTP por correo.
- [ ] Paciente conserva flujo definido.
- [ ] No hay códigos OTP en logs productivos.
- [ ] Rate limit/resend/TTL vigentes.

## K. Infraestructura y seguridad
- [ ] HTTPS.
- [ ] CORS/Sanctum.
- [ ] APP_KEY segura.
- [ ] OTP_HASH_KEY segura.
- [ ] secretos fuera de Git.
- [ ] PostgreSQL no expuesto públicamente.
- [ ] backups.
- [ ] logging.
- [ ] health/live.
- [ ] health/ready.
- [ ] monitoreo de errores.
- [ ] branch protection de `main`.
- [ ] required checks antes de merge.

## L. Evidencia de CI
- [ ] Backend CI verde.
- [ ] Frontend CI verde.
- [ ] Android CI verde.
- [ ] iOS CI verde.
- [ ] Sin jobs que fallen antes de iniciar pasos.

### Incidencia actual
A fecha 28/09/2026 los jobs recientes de GitHub Actions terminan antes de exponer pasos de ejecución (`steps = null`) en los tres stacks. Esto se trata como blocker de infraestructura CI hasta identificar el mensaje de cuenta/runner en la interfaz de GitHub Actions.

## M. Autorización
- [ ] Auditoría final emitida.
- [ ] Sin P0 abiertos.
- [ ] Usuario autoriza explícitamente merge a `main`.
- [ ] Merge.
- [ ] Deploy.
- [ ] Smoke post-deploy.
- [ ] Acta/bitácora de release.
