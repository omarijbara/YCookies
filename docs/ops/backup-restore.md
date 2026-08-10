# Datenbank-Backup & Restore (Spatie Laravel Backup)

YCookies nutzt **`spatie/laravel-backup`** mit Fokus auf **nur Datenbank** (`backup:run --only-db`), siehe `config/backup.php` und geplanten Schedule.

## Backup ausführen

```bash
php artisan backup:run --only-db
php artisan backup:list
```

In Docker/Coolify: im **Laravel-Container** dieselben Befehle (siehe `.agents/rules/how-to-access-api-ssh-production.md`).

## Restore-Smoke (Release-Checklist)

1. **Dump sichern** (aktueller Stand).
2. Auf **leerer** Test-DB (oder lokalem SQLite/MySQL-Clone):
   - Backup-Zip aus `storage/app/Laravel/…` (Pfad laut `backup:list`) entpacken.
   - SQL-Datei aus dem Archiv mit `mysql` / Admin-Tool einspielen **oder** bei SQLite die Datei ersetzen.
3. **`php artisan migrate:status`** — erwartete Migrationen je nach Stand.
4. **`php artisan test`** oder minimale Smoke: `/up`, Admin-Login.

Wenn dieser Ablauf einmal dokumentiert und durchgespielt ist, kann die Checklist-Position **„spatie/laravel-backup runs + restore test passes“** abgehakt werden.

## Produktion

- Retention und Ziel-Disks über `config/backup.php` und Env (`BACKUP_DISK`, …).
- `mariadb-client` im Image ist für mysqldump nötig (bereits in Deploy-Notizen erwähnt).
