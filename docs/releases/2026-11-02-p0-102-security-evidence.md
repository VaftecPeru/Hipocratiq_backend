# HIPOCRATIQ — P0 #102: auditoría de seguridad y evidencias sin secretos

Fecha de preparación: 02/11/2026. **Este documento no certifica ausencia de filtraciones y NO autoriza publicar producción.**

## Evidencia verificada

- Los repositorios Frontend y Backend figuran **públicos**. Su visibilidad debe confirmarse como decisión expresa del propietario del software y del cliente.
- La auditoría histórica Backend de GitHub Actions reportó 25 **versiones/blobs** de plantillas con valores sensibles no vacíos y 1 archivo de entorno rastreado. **No son 25 claves diferentes ni demuestra que alguna siga vigente.**
- Hubo un reporte previo de cuatro coincidencias sujetas a clasificación privada. Es una cifra histórica de revisión, no hallazgo verificado como incidente ni prueba de que estén expuestas las cuatro.
- El escáner de Frontend sin señales no confirma ausencia de secretos.
- La API clásica de protección de ramas puede devolver 404 aun cuando existan **GitHub Rulesets** activos. Revisar reglas efectivas desde settings/Rulesets antes de declarar ramas desprotegidas.

## Nuevo inventario de metadatos (no contenido)

`.github/workflows/security-actions-artifact-metadata.yml` ejecuta pruebas sintéticas y llama únicamente a:
- Listado paginado de metadatos de GitHub Actions artifacts del repositorio Backend.
- Listado paginado de metadatos de releases y cantidad de assets del mismo repositorio.

Solo guarda números agregados: cantidad de artifacts disponibles, vencidos, releases, assets y páginas. **No descarga ni lee** ZIP, adjuntos, assets, logs, secretos, .env, volcados de BD ni identificadores de paths. Se corta ante errores, paginación incompleta o permisos insuficientes. La salida siempre conserva `security_clearance_granted=false`.

La revisión manual privada debe ser realizada por personal autorizado y **NO** dentro del issue público o un artefacto de Actions sin control de acceso. Se debe cubrir también Frontend, historial completo, artifacts caducados o eliminados si existe copia, releases, clones/forks, tickets y servicios externos.

## Orden correcto para remediar

1. Designar responsable de seguridad. Hacer inventario confidencial de las coincidencias del historial Git y revisar los registros de acceso del proveedor. **No enviar valores ni capturas con secretos a issues, PR, logs o chats.**
2. Clasificar cada señal como real/ejemplo/falso positivo, exposición posible y vigencia. En caso de duda, tratar como comprometida.
3. **Rotar o revocar primero en el servicio emisor**, revisar uso anómalo, caducidad, permisos, account bindings y despliegues. Para tokens AWS, IAM, pasarelas, OTP, SMS, mensajería y BD: cambiar de forma coordinada con los dueños respectivos y evitar interrupción. La rotación de Laravel APP_KEY puede invalidar datos cifrados y sesiones; requiere plan específico. **No rotar a ciegas ni poner nuevos secretos en git.**
4. Revisar entorno productivo/Secrets Manager/Settings Secrets mediante acceso administrador autorizado, sin solicitar secretos en texto.
5. Tras rotación y confirmación de producción, decidir con el propietario si conservar o reescribir historial público. Reescribir git no borra forks, clones, cachés ni versiones ya descargadas. **Nunca usar force push sin plan, aprobación y respaldo.**
6. Verificar GitHub Rulesets, restricciones de environments y checks; documentar únicamente número/fecha de acta privada, estado de revisión por proveedor y responsable.
7. Mantener #102 **OPEN** hasta que el responsable firme revisión, las rotaciones aplicables, inspección privada de artifacts/releases y aceptación del riesgo residual. Gates #92 clínica, #113 restore, AWS reales continúan independientes.

## Evidencia permitida para el issue

- URL del run y SHA exacto de la auditoría.
- Conteos agregados, no rutas ni valores.
- `PRIVATE_REVIEW_PENDING`, `ROTATION_VERIFIED_BY_OWNER` y referencias opacas de acta si las acredita el responsable.
- No afirmar `NO_SECRETS`, `MAIN_READY` ni `PRODUCTION_SAFE` solo por CI verde.

**Estado esperado por defecto:** `NO-GO`.
