# HIPOCRATIQ Backend — Conciliación de historial y gates de release

Fecha de corte: 2026-10-23. Entorno: preparación; **sin autorización de despliegue**.

## Base verificada

- `develop` antes de este PR: `5746e27874cf25cabc4309c0929ba9751022a261`.
- `main` productivo sin cambios: `c99c6e9391ea44986997c7a49c311b4177e6f731`.
- El árbol de `main` es idéntico al árbol del ancestro común `733b1812da7767b46e71c6b0c503ae396ba51ca3` (SHA de árbol `6f0709c5e6cab8da7afc5981346546a7d41d7635`).
- Los dos commits exclusivos en `main` son commits de release/merge **sin cambios netos de archivos** respecto al ancestro común. Los cambios funcionales existentes en `develop` se preservan.
- Esta rama incorpora el historial de `main` como segundo padre de un commit de conciliación cuyo árbol corresponde íntegramente al trabajo de `develop` más este documento.

## Condiciones de liberación (bloqueantes)

- [x] CI Backend `develop` y UAT PostgreSQL sintético aprobados antes del PR de conciliación.
- [ ] CI posterior al merge de PR #82 terminado y aprobado (`#40041665004` en ejecución en el corte).
- [ ] Pruebas de aislamiento multi-tenant por clínica A/B, permisos, revocación y trazabilidad en entorno UAT HTTPS.
- [ ] Pruebas OTP reales `+52` con proveedor autorizado, límites 5 min / 5 intentos, reenvío y fallos del proveedor.
- [ ] Revisión de secretos, TLS/CORS/cookies/Sanctum y persistencia de sesión/Redis en staging.
- [ ] Verificación de backup **real**, restauración en entorno aislado y plan de rollback de esquema, servicios e imágenes.
- [ ] Aprobación del cliente sobre UAT clínica integral y matriz de roles.
- [ ] Protección de la rama `main` con revisión de PR y verificaciones obligatorias; GitHub indicó `protected=false`.
- [ ] Validación pre-deploy en VPS, inventario de versiones y ventana de cambio.
- [ ] Autorización expresa del responsable para cualquier merge a `main` y ejecución de deploy AWS.

## Salvaguardas

Este PR se dirige solo a `develop`; no ejecuta migraciones ni altera contenedores, claves, secretos o datos clínicos. Los pipelines aislados no prueban conectividad real con AWS ni autorizan producción. El script `scripts/deploy-production.sh` requiere rama `main` y hace backup previo sin restauración automática.
