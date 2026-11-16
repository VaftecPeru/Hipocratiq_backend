# P0-102 — ejecución confidencial local (Backend / Frontend)

Esta guía y el script **no constituyen una auditoría ejecutada**. Su uso requiere un equipo aislado autorizado y un responsable de seguridad. No se deben generar informes con valores de secretos en GitHub Actions, PR, issues, artifacts, chat ni carpetas sincronizadas.

1. Preparar una estación con Docker y Git, acceso restringido, almacenamiento cifrado, sin sincronización ni respaldos automáticos a sistemas compartidos.
2. Obtener una copia completa del repositorio (incluidas ramas y tags) mediante una sesión autorizada. Verificar `git rev-parse --is-shallow-repository` devuelve `false`; ejecutar `git fetch --prune origin '+refs/heads/*:refs/remotes/origin/*'` y `git fetch --tags origin`. No usar un clone parcial.
3. Crear un directorio **fuera del checkout**, en disco cifrado, accesible solo al revisor. Desde la raíz del repositorio ejecutar:

```bash
bash scripts/security/p0-102-private-gitleaks.sh /ruta/absoluta/privada/hipocratiq-security
```

4. El escaneo utiliza el digest de Gitleaks fijado en el CI, desactiva la red del contenedor y guarda `findings.json` y `scan.log` exclusivamente en el directorio privado, con permisos restrictivos. **Ambos archivos pueden contener secretos**: no abrirlos en herramientas externas ni copiarlos a issues o pull requests.
5. Clasificar cada detección localmente: falso positivo justificado, secreto real revocado, secreto real vigente o estado desconocido. Comprobar proveedor, vigencia y registros de uso mediante cuentas autorizadas, sin enviar valores a terceros. Rotar/revocar primero las credenciales expuestas; registrar evidencia sensible únicamente en custodia privada.
6. Inspeccionar también artifacts y releases históricos en un canal autorizado: Gitleaks Git no los cubre. Decidir visibilidad pública/privada con propietario. No reescribir historial ni modificar AWS sin aprobación separada.
7. Publicar en GitHub solo totales agregados, decisiones y aprobación de Seguridad/PM; nunca archivos, SHAs identificadores de hallazgos, coincidencias o valores de credenciales. Mantener #102 NO-GO mientras existan hallazgos sin clasificación o secretos sin revocar.
8. Al concluir, aplicar la política de retención y eliminación segura del material confidencial. Confirmar resultados con el equipo antes de solicitar revisión de PR #183 (Backend) / #102 (Frontend).

Este script es una ayuda para revisión privada, **no** cambia los controles de CI, no limpia automáticamente secretos, no habilita `main`, no despliega y no crea infraestructura.
