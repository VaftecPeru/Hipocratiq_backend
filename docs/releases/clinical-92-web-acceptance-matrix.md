# HIPOCRATIQ — Matriz de aceptación clínica Web #92
Estado: **BORRADOR PARA EJECUCIÓN Y FIRMA CLÍNICA**. NO constituye UAT efectuada.

## Precondiciones
- Datos exclusivamente sintéticos; nunca expedientes reales ni datos identificables.
- Entorno de pruebas aislado del tráfico clínico y de producción, si no puede garantizarse: NO-GO.
- Congelar SHA Backend, Frontend y matriz de permisos por roles; registrar versión y fecha.
- Alcance: web/API; móvil excluido de este release hasta aceptación separada.

| ID | Escenario | Resultado esperado | Evidencia requerida |
| --- | --- | --- | --- |
| C01 | Inicio de sesión válido e inválido | Sesiones seguras, errores sin filtración | Captura/log sanitizado |
| C02 | OTP vigencia, reintento y límite | Expiración y bloqueo correctos | Informe sintético |
| C03 | Tenant A intenta leer paciente del tenant B | Acceso denegado 403/404 | Test con cuentas sintéticas |
| C04 | Médico sin permisos accede a expediente | Acceso denegado | Matriz RBAC firmada |
| C05 | Paciente accede a su expediente | Solo registros autorizados | Evidencia anonimizada |
| C06 | Recepción modifica historia clínica | Operación denegada | Evidencia por rol |
| C07 | Alta/edición clínica autorizada | Trazabilidad y autor correcto | Auditoría de evento |
| C08 | Archivos clínicos y resultados | Tipos/tamaño/permisos seguros | Evidencia de carga sintética |
| C09 | Concurrencia y errores transaccionales | Sin pérdida ni mezcla de datos | Resultado de prueba |
| C10 | Recuperación y salida de sesión | Revocación y cierre correctos | Test automatizado/manual |
| C11 | Navegación, accesibilidad y errores UI | Flujos web sin bloqueo P0 | Acta de revisión |
| C12 | Registro de auditoría | Cambios vinculados a actor/tenant | Logs sin PHI |

Por escenario registrar: ejecutor, fecha UTC, SHA, PASS/FAIL, evidencia privada, defecto vinculado y re-test. Requiere aprobación nominativa del responsable clínico y responsable de seguridad, cierre de defectos P0/P1 y aprobación del alcance web.

Un CI verde no equivale a aceptación clínica; sin pruebas ejecutadas y acta firmada: **NO-GO**.
