# HIPOCRATIQ — plan ejecutable de controles P0/P1 (102, 92, 113, 111)

Fecha de corte: 2026-11-07. Esta guía **no certifica ningún GO clínico, auditoría de secretos, restauración real ni infraestructura UAT**. Es una secuencia fail-closed para ejecutar controles con evidencia independiente; no contiene datos clínicos ni secretos.

## Orden de dependencias y criterios comunes

1. **#102** aprobar visibilidad y auditar secretos e historial de ambos repositorios, artifacts y releases en canal privado. Repositories actualmente públicos: Backend y Frontend. Verdes de Gitleaks sobre PR no acreditan historial completo. En caso de hallazgos, **rotar primero** mediante proveedor y contener exposición; nunca pegar hashes de secretos, rutas privadas o PHI en GitHub issues.
2. **#111** aprobar diseño y presupuesto de UAT separado por escrito; solo después crear recursos separados de producción. Sin esta aprobación, no crear VPC/EC2/EBS, cambiar IAM, generar costos ni dirigir tráfico UAT a producción.
3. **#113** ejecutar recuperación de PostgreSQL con base **sintética** en CI (ya existe), y después ensayar restauración en el entorno UAT aislado con origen autorizado y datos desidentificados. Ningún acceso/copia/restore de PHI productivo permitido por este plan.
4. **#92** ejecutar 36 casos reales de la matriz con Web/API HTTPS UAT, PostgreSQL/Redis aislados y cuentas ficticias. Cerrar solo con 36/36 aprobados, ausencia P0/P1, evidencia sanitaria redactada y acta del cliente.

Reglas para todo el proceso: PRs a `develop` con CI sobre SHA exacto + revisión independiente, `main` y despliegues bloqueados hasta autorización expresa. No confundir HTTPS pública con SHA de contenedor ni CI sintético con aceptación clínica.

## #102 — Auditoría de seguridad

**Responsables:** propietario de VAFTEC (decisión de visibilidad y aceptación), seguridad (auditoría independiente y rotación), DevOps (controles GitHub).

- [ ] Confirmar por escrito si cada repo debe permanecer público conforme al contrato/licencia/confidencialidad. **No cambiar visibilidad sin autorización específica**.
- [ ] Ejecutar en entorno restringido una auditoría de **historial completo** (incluidos commits borrados/referencias/tags y forks pertinentes) con herramientas de detección de secretos; comparar con inventario de credenciales activas.
- [ ] Auditar artifacts, releases y adjuntos accesibles públicamente, variables, dumps/backups y archivos de configuración; registrar hallazgos solo por conteos, severidad y ticket privado.
- [ ] Rotar credenciales comprometidas, revocar tokens y verificar trazas de acceso con proveedores; no publicar secretos ni salidas sin censurar.
- [ ] Verificar reglas efectivas main/develop en BE y FE: PR reviews, checks requeridos, bypass, force-push y restricciones; `protected=true` no prueba cada regla.
- [ ] Obtener dictamen de seguridad firmado, con evidencia privada y resumen público **sin identificadores sensibles**.

**Gate:** no cerrar #102 con un escaneo parcial ni con repo público sin decisión documentada.

## #111 — Aprobación de entorno UAT AWS independiente

**Arquitectura propuesta, sujeta a cliente:** región us-east-2, **VPC diferente de la producción** y segmentación privada; SG mínimos, EC2 dimensionada con métricas, Docker para Next.js + Laravel + PostgreSQL 17 + Redis 8; TLS y DNS UAT exclusivos, almacenamiento EBS cifrado, backups/snapshots cifrados, registros con retención, Secrets Manager/SSM Parameter Store con acceso mínimo, OIDC GitHub con trust restringido al Environment UAT y revisión de terceros; sin permisos de despliegue desde rol de inventario read-only.

**Decisiones antes de aprovisionar:**
- [ ] Dimensionamiento: CPU/RAM/IOPS y concurrencia, tamaño BD sintética, necesidades Android OTP, RTO/RPO y duración de UAT.
- [ ] Presupuesto escrito y límite de gasto mensual aprobado por cliente; comparar EC2/EBS/almacenamiento de backups, IPv4 pública, salida de datos, NAT/VPC endpoints, logs, SMS y posible KMS. **No asumir cifras sin una cotización AWS actual**.
- [ ] Titular de cuenta/centro de costos, responsables IAM/DevOps/QA/cliente, ventana UAT y autorización de creación.
- [ ] Verificar en AWS los nuevos IDs UAT (cuenta, región, EC2, VPC, EBS, SSM) **sin confundirlos con producción** y crear artifact saneado.

**Gate:** #111 no se cierra por presentar este diseño: requiere autorización cliente y evidencia de segregación real.

## #113 — Restauración PostgreSQL aislada, sin PHI productivo

- [x] Ensayo CI con PostgreSQL 17 y datos sintéticos documentado en PRs previos. **No equivale a restauración operacional real**.
- [ ] Reservar UAT separado, almacenamiento cifrado privado y permisos temporales restringidos; definir retención/expurgo y custodio.
- [ ] Obtener origen sintético o desidentificado con autorización; no usar dumps de producción salvo una aprobación explícita y garantías legales/técnicas independientes.
- [ ] Generar dump consistente (`pg_dump --format=custom`), hash SHA-256, cifrado en tránsito/reposo y manifiesto de versión PG/fecha **sin datos identificables**.
- [ ] Restaurar mediante `pg_restore` en una instancia/base aislada sin conectividad a producción, ejecutar verificación de tablas, secuencias, FK, roles limitados y datos centinela sintéticos.
- [ ] Medir tiempo real de recuperación y RPO/RTO, comprobar salud de API contra destino aislado y practicar rollback sin pérdida, adjuntando solo conteos, hashes del paquete cifrado y acta sanitizada.
- [ ] Obtener aceptación del responsable de recuperación y política de destrucción al concluir.

**Gate:** ninguna restauración en producción ni cambio en volúmenes EBS bajo #113.

## #92 — UAT clínica, Web + API + Android

Fuente de verdad: [matriz Google Sheets de 36 escenarios](https://docs.google.com/spreadsheets/d/1SwuLEHgvPyDVfQC3xic-Fg4d1NzUY4vrl0WCEfEBhls/edit), pestaña `UAT CLINICA PRODUCCION`. Nombre heredado de pestaña **no autoriza ejecutar contra producción**.

**Secuencia por grupos, sin alterar estados sin evidencia:**
- UAT-001–005: aislamiento, HTTPS, salud PG/Redis, secretos IAM y CORS/CSRF/Sanctum.
- UAT-006–011: OTP +52 y correo con proveedores autorizados, intentos, caducidad y revocación.
- UAT-012–015: aislamiento clínicas A/B, ataques IDOR, suspensión y revocación inmediata.
- UAT-016–025: citas, consulta/expediente, firma inmutable SHA-256, consentimiento, IA con umbrales y FHIR.
- UAT-026–029: laboratorio y Web/Android en dispositivo real apuntando a HTTPS UAT aislado, paquete móvil firmado según alcance.
- UAT-030–036: respaldo/restauración/rollback, auditoría, incidente y aceptación final conforme a matriz.

En cada caso registrar: fecha, SHA exacto BE/FE, URL UAT **sin credenciales**, dataset ficticio, responsable, resultado `PASS/FAIL/BLOCKED`, identificador de evidencia protegida y firma QA/cliente. Repetir los fallidos; no usar porcentajes ficticios ni marcar `PASS` por CI sintético.

**Gate:** 36/36 PASS UAT real + aceptación clínica escrita y cierre de P0/P1. Para release móvil también verificar certificados de firma y dispositivo Android UAT, independientemente del CI debug.

## Criterio final de publicación

Mantener Backend PR #90 y Frontend PR #80 en DRAFT, NO-GO, hasta cerrar #102, #111 (o decisión formal alternativa), #113 y #92. #112 (cifrado EBS productivo) exige autorización y ventana separada; no está incluido en este PR. Ningún cambio de `main`, DNS, servicios EC2 ni recursos AWS mediante esta documentación.
