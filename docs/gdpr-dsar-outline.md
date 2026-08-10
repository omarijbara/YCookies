# DSAR / Löschung — technischer Rahmen (Checklist: „Data deletion/export“)

> Keine Rechtsberatung. **Vertrags- und Registerarbeit (Art. 28/30):** siehe **[DSGVO Legal-Handoff](gdpr-legal-handoff.md)** — Vorlagen für Verzeichnis der Verarbeitungstätigkeiten, DPA-Checkliste und Subprozessoren.

Legal ergänzt **Fristen**, **Weisungskanäle** und **freigegebene Vertrags-PDFs** (außerhalb Repo).

## Bereits im Produkt

- **Consent-Logs:** Export CSV/XLSX (Filament), **Purge** nach Retention (`ycookies:purge-consent-logs`, Admin-Aktion).
- **IP in Logs:** nur `ip_hash`, kein Roh-IP in `consent_logs`.

## Empfohlener DSAR-Ablauf (Export)

1. **Identität** des Antragstellers klären (Legal/Support).
2. **Tenant (Group)** und betroffene **Domains** zuordnen.
3. Daten liefern, die ihr ohne neue Software exportieren könnt:
   - Consent-Logs: bestehender **Export** (Zeitraum/Domain filtern).
   - Stripe/Cashier: Rechnungen/Kundenportal (falls zutreffend).
   - Weitere personenbezogene Daten: ggf. **SQL-Export** nur für betroffene Zeilen (Support/Ops), nach Policy.

## Löschung

- Consent-Logs: Retention-Purge + manuelle Löschung nach Vorgabe Legal.
- **User-Account:** Standard Laravel/Filament (User-Zeile, Pivot `group_user`, Rollen) — orphan user accounts (no other group memberships) are automatically deleted by the service.
- **Tenant komplett:** Deletes Group, Users (if orphan), Domains, Cookie Bars, Services, Webhook Endpoints, Content Blockers, Script Blockers, and all associated logs/analytics.

## Artisan (Tenant-Export / -Löschung)

- **`ycookies:gdpr:export {group_id}`** — JSON-Export für DSAR (Implementierung: `App\Services\GdprService`).
- **`ycookies:gdpr:delete {group_id}`** — permanente Löschung der Gruppe inkl. zugehöriger Daten; ohne `--force` interaktive Bestätigung.

## Tests (Regression)

Die Prozesse sind in **`tests/Feature/GdprServiceTest.php`** automatisiert getestet (Export, Deletion, User-Management).

Nur nach **Legal-/Support-Vorgabe** ausführen; Umfang und Aufbewahrungspflichten klären.

Wenn dieser Ablauf intern freigegeben ist, Checklist-Punkt **„Data deletion/export capability“** als **Prozess dokumentiert** markieren; vollautomatischer Self-Service kann v1.1 sein.
