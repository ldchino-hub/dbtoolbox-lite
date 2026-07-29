# DB Tool Box Lite — Instalación en hosting (FTP)

Guía de una página para instalar **DB Tool Box Lite** en hosting compartido (GoDaddy, cPanel, etc.) **sin compilar nada**.

## Requisitos

- PHP **8.1+** con extensiones: `pdo`, `pdo_sqlite`, `pdo_mysql`, `openssl`, `json`, `mbstring`
- `pdo_pgsql` opcional (solo si usarás PostgreSQL)
- Apache con `mod_rewrite` **o** Nginx con fallback a `index.php`
- FTP o administrador de archivos del hosting
- **No** necesitas Node.js ni Composer en el servidor

## 1. Descargar

En GitHub → **Releases** → descarga `dbtoolbox-lite-X.Y.Z.zip`.

O desde la rama `main`: [dbtoolbox-lite/archive/refs/heads/main.zip](https://github.com/ldchino-hub/dbtoolbox-lite/archive/refs/heads/main.zip)  
(en ese caso usa la carpeta `dbtoolbox-lite-main/app/`).

## 2. Subir por FTP

Descomprime el zip y sube **todo el contenido** de la carpeta del release a la raíz de tu hosting (home FTP).

Estructura esperada en el servidor:

```
/                    ← home FTP
  public/            ← document root del dominio
  src/
  config/
  migrations/
  scripts/
  storage/
  INSTALL-LITE.md
  .htaccess          ← fallback si el docroot no es public/
  index.php          ← fallback si el docroot no es public/
```

**Importante:** sube `public/`, `src/`, `config/`, etc. No subas solo `public/`.

## 3. Document root

En el panel del hosting, apunta el dominio a la carpeta **`public/`**:

```
https://tu-dominio.com  →  /home/tu-usuario/public
```

No expongas `config/`, `src/` ni `storage/` como document root.

Si **no puedes** cambiar el document root, deja el docroot en la raíz del proyecto: los archivos `.htaccess` e `index.php` de la raíz redirigen todo a `public/`.

## 4. Configuración

Por FTP, copia:

```
config/config.example.php  →  config/config.php
```

Edita `config/config.php`:

| Clave | Valor |
|-------|-------|
| `meta_enc_key` | 64 caracteres hex (32 bytes). Genera en tu PC: `openssl rand -hex 32` |
| `jwt_secret` | Mínimo 16 caracteres. Ej.: `openssl rand -hex 16` |
| `admin_email` | Tu email de administrador |
| `admin_password` | Contraseña inicial del admin |

**Nota:** `database_path` es la base **interna** de la app (usuarios, conexiones guardadas). Usa SQLite en `storage/` y no requiere configurar MySQL. El bloque `database` comentado en el ejemplo solo aplica si quieres metadatos en MySQL. Tus servidores MySQL/PostgreSQL se agregan después desde la interfaz web.

### MAMP (subcarpeta en htdocs)

Si instalas en `htdocs/dbtoolbox-lite-1.0.2/` y ves **404** al abrir `/public/`:

```bash
cd htdocs/dbtoolbox-lite-1.0.2
# Edita YOUR_FOLDER en los archivos antes de copiar, o sustituye con sed:
sed 's/YOUR_FOLDER/dbtoolbox-lite-1.0.2/g' public/.htaccess.mamp > public/.htaccess
sed 's/YOUR_FOLDER/dbtoolbox-lite-1.0.2/g' deploy/root.htaccess.mamp > .htaccess
```

Luego abre: `http://127.0.0.1/dbtoolbox-lite-1.0.2/public/`  
Diagnóstico: `http://127.0.0.1/dbtoolbox-lite-1.0.2/public/check.php`

## 5. Permisos

Crea (si no existen) y deja escribibles:

```
storage/
storage/backups/
```

En cPanel/FTP: permisos **775** o **755** según el hosting. La app debe poder crear `storage/database.sqlite`.

## 6. Usuario administrador

### Con SSH (recomendado)

```bash
cd /ruta/a/tu-instalacion
php scripts/seed-admin.php
```

### Solo FTP (sin SSH)

1. Crea un archivo vacío: `storage/.recover-admin`
2. Sube `public/recover-admin.php` (ya viene en el zip)
3. Abre **una vez**: `https://tu-dominio.com/recover-admin.php`
4. Inicia sesión con `admin_email` y `admin_password` de `config.php`
5. **Borra** `public/recover-admin.php` y `storage/.recover-admin`

## 7. Verificar

Abre `https://tu-dominio.com/check.php` — debe marcar ✓ en PHP, extensiones y build.

Prueba la API: `https://tu-dominio.com/api/health`  
Debe responder algo como:

```json
{"ok":true,"version":"1.0.2","edition":"lite"}
```

**Elimina `check.php`** cuando todo esté bien.

## 8. Iniciar sesión

Abre `https://tu-dominio.com`, inicia sesión con el admin y añade tus conexiones MySQL/PostgreSQL.

---

## Nginx (referencia)

```nginx
root /ruta/a/tu-instalacion/public;
index index.php index.html;

location /api/ {
    try_files $uri /index.php?$query_string;
}
location / {
    try_files $uri $uri/ /index.html;
}
location ~ \.php$ {
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

## Actualizar

1. Haz backup de `config/config.php` y `storage/`
2. Sube el zip nuevo **sin** sobrescribir `config/config.php` ni `storage/`
3. Recarga con **Cmd+Shift+R** (o Ctrl+Shift+R)

## Soporte

- Repo: [github.com/ldchino-hub/dbtoolbox-lite](https://github.com/ldchino-hub/dbtoolbox-lite)
- Demo: [db.ldjr.me](https://db.ldjr.me)
