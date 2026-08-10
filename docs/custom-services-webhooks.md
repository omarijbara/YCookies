# Custom Services, Blocker-Regeln & Webhooks

Anleitung für das, was im aktuellen Code wirklich vorhanden ist. Pfade beziehen sich auf das **Filament-Admin** unter deiner `APP_URL` / `ADMIN_HOST`.

## Services (inkl. „Custom“-Tracker)

Ein **Service** beschreibt einen Tracker/Embed inkl. Cookie-Gruppe, Provider und optional Consent-Mode-Mapping. Es gibt keine separate Schaltfläche „Custom Service“ — du legst einen normalen Service an und pflegst Inhalte manuell.

1. Sidebar: **Consent Management** → **Services** (Resource `ServiceResource`).
2. **Create** → Pflichtfelder u. a.:
   - **Master Group** (Mandant),
   - **Assigned Domains** (mindestens eine Domain),
   - **Cookie Group**,
   - **Provider** (ggf. über „Create“ anlegen),
   - **Service Name** und **Key** (stabiler interner Schlüssel).
3. Optional: Sektion **Cookies** (Repeater), **Purpose**, **Additional Settings** (z. B. GTM/GA-Felder, Opt-in/Opt-out/Fallback-Code — abhängig vom Service-Key).
4. Sektion **Consent Execution** (eingeklappt):
   - **Integration Type** z. B. `browser_tag`, `script_blocker`, `embed_provider`, …
   - **Known Domains** (`service_domains`) — Domains für Matching/Blocking im Proxy.
   - **Consent Mode v2 Mapping** bei Bedarf.

Nach **Save** Manifest/Revision wie gewohnt **publishen**, damit der Proxy die Konfiguration zieht.

## Script- und Content-Blocker (Domain-Ebene)

Globale Ressourcen (nicht nur „pro Service“):

- **Script Blockers** — URL-/Pattern-Regeln für `<script>`-Blocking bis zur Einwilligung.
- **Content Blockers** — z. B. Embeds (Video/Karten), oft iframe-bezogen.

Anlegen über die jeweiligen Filament-Ressourcen (Script Blockers / Content Blockers) mit Zuordnung zur **Domain**. Scanner und manuelle Pflege ergänzen sich; Details siehe [Proxy-Setup](proxy-setup.md).

## Webhooks — was heute unterstützt wird

### 1. Stripe (Laravel Cashier) — Billing

Für Abos und Limits nutzt das Projekt **Laravel Cashier**. Stripe sendet Ereignisse an den von Cashier registrierten Webhook-Endpunkt (Standard-Pfad in Laravel-Apps: **`POST /stripe/webhook`** — exakte URL: `https://<dein-admin-host>/stripe/webhook`).

**Selbst hosten:**

- Im Stripe Dashboard den Endpunkt anlegen und das **Signing Secret** setzen (in der Regel `STRIPE_WEBHOOK_SECRET` bzw. entsprechende Cashier-`.env`-Keys — siehe [Laravel Billing: Webhooks](https://laravel.com/docs/billing#handling-stripe-webhooks)).
- In `AppServiceProvider` werden bei eingehenden Cashier-Webhooks die Typen `invoice.payment_failed` und `customer.subscription.deleted` ausgewertet; daraus wird u. a. `ycookies:enforce-limits` gequeued.

Es gibt **keinen** generischen „YCookies Webhook Secret“ im Sinne einer eigenen Integrations-UI für beliebige Ziel-URLs.

### 2. Ausgehende Produkt-Webhooks (YCookies → deine URL)

Pro **Mandant (Group)** kannst du unter **System → Webhooks** Ziel-URLs anlegen. Lieferung erfolgt **asynchron** über die Queue (`DeliverOutboundWebhook` auf Queue `default`).

**Aktuell unterstütztes Event**

| Event | Auslöser |
|--------|-----------|
| `scan.completed` | Nach erfolgreichem Speichern eines `ScanResult` (automatischer Job `ScanDomainCookies` **und** manueller Lauf im Filament **Scanner**, sofern eine Domain gewählt ist). |

**HTTP-Anfrage**

- Methode: `POST`
- Header: `Content-Type: application/json`, `X-YCookies-Event: <event>`, `X-YCookies-Signature: <hex>` (HMAC-SHA256 über den **rohen** JSON-Body mit dem gespeicherten Signing Secret), `User-Agent: YCookies-Webhook/1.0`
- Body (Beispiel): `{"event":"scan.completed","timestamp":"…","domain_id":1,"site_id":"…","scan_result_id":42,"status":"success","data":{…}}`

**Verifikation beim Empfänger**

```php
$body = $request->getContent();
$expected = hash_hmac('sha256', $body, $webhookSecret);
hash_equals($expected, $request->header('X-YCookies-Signature'));
```

**Hinweise**

- Das Secret wird in der Datenbank **verschlüsselt** gespeichert (wie andere Admin-Geheimnisse).
- Bei HTTP-Fehlern wird der Job mit Backoff erneut versucht; erfolgreiche Antworten: jeder **2xx**-Status.
- Nach Deploy ggf. **Filament Shield**-Berechtigungen für die neue Ressource erzeugen/zuweisen (`php artisan shield:generate` o. ä.), sofern ihr keine reine-`super_admin`-Umgebung habt.

### 3. Spatie Backup (optional)

In `config/backup.php` können **Discord/Slack-Webhook-URLs** für Backup-Benachrichtigungen konfiguriert werden — das betrifft Betrieb/Monitoring, nicht CMP-Fach-Events.

## Siehe auch

- [API-Referenz](api-reference.md) — öffentliche `/api`-Routen  
- [Migration von anderen CMPs](migration-guide.md)  
- [Proxy-Setup](proxy-setup.md)  
- [Troubleshooting FAQ](troubleshooting-faq.md)  
