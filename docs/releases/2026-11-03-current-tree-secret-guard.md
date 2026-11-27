# HIPOCRATIQ — Control preventivo de secretos actuales (P0 #102)

El CI Backend incluye `scripts/check_current_tracked_credentials.py`, ejecutado **antes** de crear `.env`.

Alcance: ficheros del índice Git que conforman el candidato evaluado, sin credenciales de AWS ni acceso a la base de datos.

Verifica:
- archivos de entorno productivo o local rastreados accidentalmente;
- ficheros de credenciales, claves privadas y exportaciones de respaldo;
- formatos de alta confianza de tokens AWS, GitHub, Stripe, OpenAI y encabezados de claves privadas;
- fallos de lectura, entradas no regulares, enlaces simbólicos y textos demasiado grandes para examinar.

Si se detecta un riesgo, devuelve exit code != 0 para **bloquear el CI**. La salida contiene solamente **categorías y conteos**, nunca rutas, líneas, valores ni hashes. Las pruebas utilizan únicamente valores sintéticos, construidos sin colocar tokens completos en archivos del propio repositorio.

**Limitaciones:** no es una auditoría integral Gitleaks ni revisa historial antiguo, GitHub Actions artifacts, releases, forks, clones, credenciales de proveedores, permisos efectivos ni datos clínicos. Un resultado limpio significa exclusivamente que el **árbol rastreado actual** no contiene las señales cubiertas por esta regla. Seguridad #102 permanece P0 abierto y no existe autorización automática para `main`.

**Actuación ante un fallo:** revisar la evidencia en un canal de acceso restringido, retirar el archivo actual o reemplazar el ejemplo sintético si procede y, cuando haya exposición real, **revocar/rotar primero** el secreto en el proveedor. No poner secretos en issues, PR, logs o chats; no reescribir el historial sin aprobación explícita ni sustituir una rotación por un borrado.

**Responsable administrador:** mantener reglas de protección de `develop`/`main`, revisiones independientes y checks obligatorios. El gate actual de Frontend informa `develop protected=false`; cambiar esa configuración requiere permisos administrativos y comprobación posterior en GitHub, no una modificación del test.

Se conserva el NO-GO por clínica #92, auditoría privada #102, recuperación #113 e inventario/autorización AWS.
