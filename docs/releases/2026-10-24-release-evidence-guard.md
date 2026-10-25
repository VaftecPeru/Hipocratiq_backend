# HIPOCRATIQ — CI de evidencia de release fail-closed

Fecha de preparación: 2026-10-24. **Estado real: NO-GO producción.**

## Objetivo

No confundir el SUCCESS del CI Laravel/Next/Flutter con la autorización clínica,
la disponibilidad AWS UAT o la preparación de una rama productiva.

- .github/workflows/release-readiness.yml: ejecuta regresiones sin credenciales.
- scripts/release_go_no_go.py: **solo lecturas GitHub API** desde el PR a main.
- tests/Scripts/test_release_go_no_go.py: casos de prueba de denegación.

## Evidencia mínima requerida al proponer Backend main

1. PR a main, abierto, no draft, desde este mismo repositorio.
2. SHA head del PR exactamente igual al SHA actual de develop.
3. API GitHub confirma `main` **y `develop` Backend** protegidas.
4. API GitHub confirma `main` **y `develop` Frontend** protegidas y Frontend PR #80 abierto, sin DRAFT, dirigido a `main` y con SHA exacto de `develop`.
5. Issue #92 de seguimiento clínico marcado cerrado **después de revisar evidencia externa**.
6. GitHub Actions, en los últimos siete días, ha ejecutado en develop el job real
   "Approved AWS UAT instance metadata ONLY", con conclusion SUCCESS (no SKIPPED)
   y artifact AWS no vacío y no expirado.

La evaluación falla cerrada si faltan permisos de lectura GitHub o si no se pueden
consultar datos. El job **no ejecuta comandos AWS ni envía SMS**.

### Alcance limitado

El job **no lee ni verifica criptográficamente el contenido del artifact AWS**,
y no puede concluir si todos los 36 escenarios de la matriz clínica fueron aceptados.
Cierre de issue, artifact y flag de protección son controles necesarios, no suficientes.

GO verdadero requiere:
- inventario AWS con report JSON INVENTORY_PASS_NOT_RELEASE_APPROVAL, administradores y
  separación UAT/producción independientemente corroborados;
- HTTPS UAT Web + API Laravel + PostgreSQL + Redis con datos sintéticos;
- OTP México real +52 con autorización explícita y sin datos sensibles en logs;
- 36/36 escenarios UAT y cero incidentes P0/P1;
- recuperación de backup cifrado en segunda instancia, rollback de código/esquema;
- pruebas Android UAT reales, firma release e iOS conforme alcance;
- revisión por responsables, protección main con required checks en ambos repos,
  acta cliente firmada y autorización explícita de publicación/operación AWS.

**No se debe cerrar el issue #92 ni marcar un PR release READY solo para hacer
pasar el gate.** Los administradores deben configurar la protección de ambas
ramas main y exigir el check del release como requerido. Crear un workflow
no activa por sí mismo la protección de una rama: con main desprotegida un
usuario con permiso suficiente todavía podría eludirlo.

## Uso de pruebas offline

    python3 -m py_compile scripts/release_go_no_go.py
    python3 -m unittest discover -s tests/Scripts -p 'test_release_go_no_go.py' -v

Estas pruebas simulan respuestas de GitHub: no usan tokens, credenciales,
datos médicos ni AWS. Una pasada en verde no significa GO a producción.

## Evidencia actual observada

- AWS Inventory #40122869866: offline SUCCESS, **job AWS SKIPPED**.
- Backend PR #90 a main: DRAFT, CI #40125942317 SUCCESS.
- Frontend PR #80 a main: DRAFT, CI release actualizado SUCCESS.
- Issue Backend #92: OPEN.
- Ambos main: protected=false.
- Por tanto este control debe emitir NO_GO si se evalúa con estos datos.

No fusionar este PR a develop sin autorización y no ejecutar merge a main ni
deploy AWS hasta que se cumplan los gates.

## Hardening cross-repo — 25/10/2026

- El guard del **Backend** comprueba también la preparación de la release **Frontend**. Sin este control, un Backend aparentemente apto podría avanzar con la rama `main` de Web/Mobile aún desprotegida o con un release candidato desactualizado.
- Se usan exclusivamente lecturas GitHub de los dos repositorios exactos `VaftecPeru/Hipocratiq_backend` y `VaftecPeru/Hipocratiq_Frontend`; sin credenciales AWS, sin SMS ni PHI.
- Nuevas regresiones rechazan `develop` sin protección, Frontend `main` desprotegida, PR Frontend DRAFT, SHA divergente, fork no aprobado y endpoint fuera de la allowlist de repositorios.
- **Hallazgo adicional pendiente de validación de política:** la API GitHub del 25/10 devuelve `private=false` en ambos repositorios, pese a que las guías de seguridad los describían privados. El administrador debe confirmar si la visibilidad pública es autorizada, inspeccionar antecedentes de secretos y definir política de acceso. **No publicar código ni cambiar la visibilidad automáticamente.**
- Aun con ambos release PR listos y ramas protegidas, el script es **evidencia mínima**, no un permiso de producción: UAT real 36/36, OTP mexicano, restore, IAM OIDC y firma del cliente permanecen P0 independientes.
