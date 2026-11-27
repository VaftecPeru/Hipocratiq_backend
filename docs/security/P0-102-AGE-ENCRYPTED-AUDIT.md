# P0 #102 — auditoría Gitleaks con evidencia cifrada (age)

**Alcance:** PR Backend #183 y PR Frontend #102, rama `security/p0-102-full-history-gitleaks-20261113`. Este proceso NO modifica `develop`, `main`, AWS, secretos de proveedores ni producción. No constituye aprobación clínica ni de seguridad.

## Custodia de claves: tarea del propietario y de `anunciosvaftec`

En una estación autorizada con almacenamiento cifrado y **fuera de carpetas sincronizadas, Git y CI**, instalar `age` y generar una identidad X25519:

```bash
umask 077
mkdir -p "$HOME/.config/hipocratiq-p0-102"
chmod 700 "$HOME/.config/hipocratiq-p0-102"
age-keygen -o "$HOME/.config/hipocratiq-p0-102/identity.txt"
chmod 600 "$HOME/.config/hipocratiq-p0-102/identity.txt"
age-keygen -y "$HOME/.config/hipocratiq-p0-102/identity.txt"
```

Guardar la **clave privada** `identity.txt` solo bajo custodia del responsable, con copia de seguridad cifrada y acceso restringido. Jamás subirla al repositorio, GitHub Secrets/Variables, issues, PR, chat ni artifacts. Verificar por un canal seguro que el destinatario de la **clave pública** realmente es el revisor autorizado. La salida de `age-keygen -y` es un destinatario público que empieza por `age1`.

Configurar el **mismo destinatario público autorizado** como variable *Repository Actions* en ambos repositorios (no es un secreto y no exige otra EC2):

- Backend: `https://github.com/VaftecPeru/Hipocratiq_backend/settings/variables/actions`
- Frontend: `https://github.com/VaftecPeru/Hipocratiq_Frontend/settings/variables/actions`
- Variable exacta: `HIPOCRATIQ_P0_102_AGE_RECIPIENT` = `age1...`

No escribir un valor de ejemplo: el flujo **falla cerrado** si la variable falta o no es válida. No usar keys personales ni tokens como variable. Un destinatario equivocado permitiría descifrar los informes a un tercero.

## Funcionamiento y límites

El workflow `.github/workflows/p0-102-encrypted-history-audit.yml` solo acepta `push` de archivos relacionados en la rama de seguridad y `workflow_dispatch` en dicha rama. Los workflows nuevos pueden requerir la habilitación de un administrador GitHub; los registros `action_required` **no** significan CI verde. Un evento `workflow_dispatch` solo es seleccionable directamente si GitHub permite ejecutar ese workflow desde su rama (normalmente el archivo debe existir en la rama predeterminada); para la primera ejecución se usa el disparo `push` restringido. Un nuevo commit autorizado en archivos observados vuelve a solicitar el flujo.

El job comprueba el destinatario **antes de leer** el historial, descarga todas las ramas/tags, ejecuta Gitleaks fijado por digest en Docker **sin acceso a red** y captura el JSON y diagnósticos solamente en directorio temporal con `umask 077`. El script `scripts/security/p0-102-encrypted-history-audit.sh` envuelve el reporte y manifest en `tar` transmitido por tubería a `age`, sin crear un tar sin cifrar. Borra los informes temporales antes del upload.

**Único artifact permitido:** `p0-102-encrypted-history-evidence`, que contiene exclusivamente `p0-102-gitleaks-report.tar.age`, con retención de **1 día**. El repositorio es público: la **copia cifrada** del reporte puede resultar accesible a terceros. La confidencialidad depende de que `identity.txt` permanezca privada y el destinatario se valide. No subir `findings.json`, `scanner.log`, SHAs de hallazgos, rutas sensibles, tokens ni PHI por otro canal.

Un hallazgo de Gitleaks provoca un fallo intencional `PRIVATE_TRIAGE_REQUIRED_NO_GO` **después** de subir la evidencia cifrada. El workflow de seguridad histórico **mantiene su bloqueo**, sin allowlist automática ni desactivación del escáner. Un resultado sin hallazgos tampoco significa aprobación ni garantiza que los secretos expuestos anteriormente estén revocados. La auditoría Git no reemplaza la inspección de artifacts, releases y forks.

## Revisión local del artifact

Descargar la evidencia desde `Actions > ejecución P0-102 > Artifacts` en una estación autorizada **antes de que expire**, y extraer el archivo `.tar.age` en un volumen cifrado y no sincronizado. Luego, por ejemplo:

```bash
umask 077
mkdir -p /ruta/privada/triage
age --decrypt --identity "$HOME/.config/hipocratiq-p0-102/identity.txt" \
  /ruta/privada/p0-102-gitleaks-report.tar.age \
  | tar -xf - -C /ruta/privada/triage
```

Revisar `manifest.txt`, `findings.json` y `scanner.log` **solo en privado**. Si se confirma una credencial vigente o dudosa, gestionar rotación/revocación y revisión de uso **con el proveedor** antes de cerrar el incidente; no cambiar credenciales ni producción desde CI. Registrar en el issue público únicamente identificador del acta privada, nombre de revisor, fecha, totales saneados y decisión de Seguridad, nunca evidencias que permitan identificar un secreto.

El responsable debe aprobar contractualmente la visibilidad pública del código/historial. Cerrar el issue #102 y convertir los PR a revisables únicamente tras evidencia confidencial, revisión de artifacts/releases y aprobación independiente. `main`/despliegue siguen sujetos al cierre separado de #92/#111/#112/#113 y autorización expresa.

## Validación técnica

- `bash -n scripts/security/p0-102-encrypted-history-audit.sh`
- Revisar sintaxis YAML y el primer run Actions contra el SHA exacto.
- Confirmar que el artifact contiene **solo** `.age`.
- Verificar que el archivo descifra con la clave privada autorizada (sin mostrar contenido ni registrarlo).
- Confirmar `P0_102_HISTORY_SCAN=PRIVATE_TRIAGE_REQUIRED_NO_GO` si hay detecciones, o `NO_SIGNAL_NOT_SECURITY_CLEARANCE` si no las hay; **nunca** convertir una detección en éxito mediante bypass.
