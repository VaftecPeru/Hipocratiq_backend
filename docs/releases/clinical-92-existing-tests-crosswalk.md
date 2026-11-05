# HIPOCRATIQ #92 — Cruce de pruebas clínicas existentes (solo revisión estática)

**Estado:** cobertura identificada en código, **NO** prueba clínica ejecutada ni acta de aceptación. Fuente: Backend `develop` a 2026-10-28. Validar versión y SHA al ejecutar.

| Escenario de la matriz web | Tests existentes que pueden aportar evidencia | Brecha para aceptación |
|---|---|---|
| C01 Acceso/autenticación | `DoctorClinicalRecordTest::test_unauthenticated_doctor_routes_are_rejected`; `PatientPortalTest::test_patient_routes_require_authentication` | Sesiones, contraseñas y errores por todos los roles |
| C02 OTP | No verificado en esta inspección | Verificar expiración, reintentos, máximo permitido y evidencias sin OTP real |
| C03 Aislamiento entre clínicas | `DoctorClinicalRecordTest::test_rejects_cross_clinic_patient_access`, `test_rejects_consultation_from_cross_clinic_doctor` | Ejecutar matriz completa de endpoints con dos tenants sintéticos |
| C04 RBAC médico | `DoctorClinicalRecordTest::test_rejects_consultation_read_by_another_doctor`, `test_rejects_consultation_for_unconnected_patient_in_clinic` | Verificar todos los roles/módulos |
| C05 Portal paciente | `PatientPortalTest::test_patient_reads_only_own_profile_and_clinical_records`, `test_patient_history_excludes_drafts_in_progress_and_canceled_consultations` | Verificación de UI web y permisos de archivos |
| C06 Recepción denegada | No verificado en esta inspección | Evidencia explícita de rechazo de edición clínica |
| C07 Escritura clínica y trazabilidad | `Phase4ClinicalUatTest::test_official_uat_journey_from_clinic_appointment_to_signed_history_and_patient_portal`; `DoctorClinicalRecordTest::test_appointment_encounter_completion_signature_and_audit_are_consistent` | Revisión y firma clínica humana |
| C08 Resultados y adjuntos | No verificado en esta inspección | Matriz de formatos, tamaños, tenant y autorización |
| C09 Concurrencia/rollback | `DoctorClinicalRecordTest::test_validation_rejects_unknown_section_and_rolls_back`, `test_closed_appointments_reject_consultations_without_orphan_records` | Prueba concurrente y recuperación ante fallos |
| C10 Revocación/cierre | `DoctorClinicalRecordTest::test_fhir_revokes_patient_appointment_and_organization_after_doctor_suspension` | Revocación de sesión/OTP y logout |
| C11 UX/accesibilidad | No verificado en esta inspección | Validación manual web de accesibilidad y navegación |
| C12 Auditoría | `ClinicalAuditTrailTest::test_clinical_audit_snapshots_are_encrypted_and_recoverable`, `test_clinical_audit_event_cannot_be_updated`, `test_clinical_audit_event_cannot_be_deleted` | Evidencia por actor/tenant, revisión privada |

## Condiciones de ejecución
- Ejecutar contra una instalación de pruebas autorizada, con pacientes y clínicas **100 % sintéticos**; no usar producción clínica activa.
- Congelar SHAs de Backend y Frontend y publicar únicamente identificadores sin PHI.
- Guardar evidencia individual PASS/FAIL, capturas sanitizadas, incidencias y retests en repositorio **privado** autorizado.
- La presencia de un test en código no acredita ejecución ni resultado PASS en el SHA de release.
- Requiere acta nominativa del responsable clínico y de seguridad. Sin ella, **NO-GO**.
