# DB Tool Box Lite

Shared-hosting-friendly PHP edition of DB Tool Box (v1.0.0).

- **Edition:** `lite` — no Scheduler, Collectors, DPA, Query Store, AI Analysis, DBA Ops, OS Shell, or in-app System Update
- **Runtime:** PHP 8.3 + Apache (Docker) or plain PHP on shared hosting
- **Prod Pi:** http://192.168.100.152:8789
- **Prod shared:** https://db.ldjr.me

## Quick start (Docker)

```bash
cp .env.example .env
# set META_ENC_KEY / JWT_SECRET / ADMIN_*
docker compose up -d --build
curl -s http://127.0.0.1:8789/api/health
```

## Shared hosting

Descarga el zip de **[Releases](https://github.com/ldchino-hub/dbtoolbox-lite/releases)** y sigue **[app/INSTALL-LITE.md](app/INSTALL-LITE.md)**.

Resumen: sube el contenido de `app/` por FTP, apunta el dominio a `public/`, copia `config/config.example.php` → `config/config.php`, deja `storage/` escribible y crea el admin.

## License

Private — ldchino-hub
