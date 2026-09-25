# Bitácora: acceso y ciclo clínico HIPOCRATIQ

Fecha: 2026-09-25. Cambios propuestos en ramas de revisión; sin merge, despliegue ni cambios a datos productivos.

Base backend: 8b3c0fa40f99ee63ca15ee2910f7f438d426c4b6. Base frontend: 2f10741a8f879806e3b53feb21140d7971203773.

## Diagnóstico y proceso operativo

El perfil del paciente y su expediente deben vincularse desde el registro/admisión. Cada atención agrega una consulta al historial; no se crea un paciente nuevo por visita. El médico ya dispone de un formulario de 15 apartados, que se conserva.

| Rol | Inicio | Trabajo | Cierre y entrega |
| --- | --- | --- | --- |
| Paciente | Solicita cita a recepción o médico | Consulta estado, completa datos y acude | Consulta atenciones completadas/firmadas, recetas y resultados disponibles |
| Recepción | Identifica o registra al paciente | Registra/confirma cita y llegada; vincula médico | Entrega la atención al médico; gestiona próxima cita/cobro en módulos existentes |
| Médico | Abre cita o consulta de paciente vinculado | Retoma borrador; documenta motivo, antecedentes, exploración, diagnóstico, tratamiento y seguimiento | Completa, revisa y firma; registro firmado queda en lectura |
| Administrador de clínica | Configura equipo, acceso y agenda | Supervisa operación y facturación de su clínica | Revisa estados y pendientes; no sustituye autoría médica |
| Administración de plataforma | Gestiona cuentas y operación | Supervisa acceso, soporte e infraestructura | Conserva trazabilidad administrativa sin copiar contenido clínico a eventos |

Actualmente el portal del paciente NO ofrece autoagendamiento: recepción o médico registran la cita. Se aclara ese recorrido en la interfaz. No se agrega automatización de diagnóstico, receta, cobro o seguimiento por completar una consulta: esos módulos conservan sus acciones explícitas.

## Primera auditoría y correcciones

- Sesión y middleware discrepaban ante varias clínicas activas: se rechaza contexto ambiguo; recuperación frontend sin bucle, con cierre de sesión. No se implementa selector de clínica.
- Una cita admitía varias consultas: creación bajo bloqueo de fila y rechazo de vínculo ya ocupado. Reabrir desde agenda retoma el registro existente.
- Consulta y agenda evolucionaban por separado: inicio cambia cita a in_consultation; completar/firmar conserva completed; cancelación clínica cambia cita a canceled. Borrador conserva estado de agenda. Atención directa por el médico puede iniciar cita pendiente/confirmada sin una visita adicional a recepción.
- Se rechazan nuevas atenciones de citas canceladas, no asistidas o completadas. Se conserva atención sin cita para pacientes vinculados.
- El vínculo consulta–cita es inmutable, incluso si nació sin cita. No se permite reasignar/reprogramar ni eliminar una cita con registro clínico. Si solo tiene borrador, se permite confirmar y registrar llegada.
- PATCH de secciones mezcla solo campos enviados y conserva los demás. sections:null y [] no borran datos. Un campo enviado vacío conserva la intención explícita de vaciarlo.
- Consultas firmadas y canceladas quedan inmutables. Firma conserva identificación profesional y digest existentes. El formulario ofrece transiciones permitidas y lectura de registros cerrados.
- Se registra creación/actualización en admin_audit_events dentro de la transacción: actor, clínica, consulta y estados. Sin motivo, diagnóstico ni texto de secciones en metadata.

## Segunda auditoría y mantenimiento

Se detectaron y corrigieron cambio de formulario durante guardado, reutilización del estado de otra cita durante navegación y bloqueo de llegada cuando había borrador. La pantalla conserva el registro guardado para continuar hasta firma, muestra historial en lugar de “consultas de hoy” para una lista sin filtro de fecha y bloquea envíos mientras guarda.

Las pruebas verifican aislamiento entre clínicas/médicos/pacientes, permisos de recepción, preservación de secciones, rechazo de duplicados sin registros huérfanos, estados y firma, bitácora sin contenido clínico y exclusión de borradores/en curso/canceladas del portal del paciente.

## Evidencia de verificación

- Backend: 88 pruebas, 890 aserciones, todas aprobadas en nueve suites de acceso, clínica, admisión, médico y portal del paciente. PHP 8.4 WASM, dependencias del lock, SQLite en memoria; no acredita concurrencia PostgreSQL real.
- Frontend: 32 pruebas de acceso/formulario aprobadas; TypeScript y build de 58 rutas aprobados. Lint sin errores; dos advertencias previas de texto alternativo en componentes PDF.
- Una ejecución ampliada previa de adaptadores obtuvo 89/90; la prueba de respaldo PostgreSQL requiere proc_open, no disponible en este runtime. Debe ejecutarse en CI nativo. No se alteró esa prueba para ocultar el bloqueo.
- No se ejecutó recorrido visual completo en navegador ni comparación exacta con Figma: falta el enlace de nodo del prototipo vigente.

## Integración y operación pendientes

Solicitar autorización para merge de ambos PR después de revisar sus checks. No desplegar automáticamente. Tras autorización y merge: publicar backend y frontend de forma coordinada, comprobar acceso por rol y una atención de prueba. Este cambio no añade migraciones ni requiere actualizar datos existentes.

Antes de despliegue, consultar en modo lectura si existen citas con consultas duplicadas o vínculos históricos inconsistentes. Este parche previene nuevas inconsistencias; no elimina ni reescribe registros clínicos anteriores. Si existen duplicados, requieren conciliación explícita, sin limpieza automática. Validar bloqueo concurrente y respaldo/restauración en PostgreSQL de staging.

La revisión es funcional y técnica de los recorridos descritos; no certifica cumplimiento clínico/legal ni todos los módulos del ecosistema. Autoagendamiento, selección multiclínica y adendas a documentos firmados quedan como evolución explícita, no como funciones implementadas en esta entrega.
