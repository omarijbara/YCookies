# Launch Checklist — Abschluss-Leitfaden (Repo vs. Notion)

**Notion (SSoT für Checkboxen):** [YCookies — Final Launch Checklist (v1.8)](https://www.notion.so/33205bf282248125b1efd88032e2d3ad)

Diese Datei sagt dir, **was du in Notion auf `[x]` setzen darfst** (nach kurzem Smoke-Test), was **Ops/Manuell** ist und was **Product/Legal** bleibt.

---

## 1. In Notion jetzt abhakbar (Code vorhanden — kurz verifizieren)

| Notion-Thema | Verifizierung | Code / Ort |
|--------------|---------------|------------|
| **E-Mail-Verifizierung** | Neuer User: Registrierung → Verifizierungs-Mail → Link → Admin-Zugang | `AdminPanelProvider`: `->emailVerification()`; `User` implementiert `MustVerifyEmail` |
| **Passwort-Reset** | Login „Passwort vergessen“ → Mail → Reset | `AdminPanelProvider`: `->passwordReset()` |
| **Einladungen + Rollen (Admin/Editor/Viewer)** | Einladung erstellen → Mail → `/invitations/accept/{token}`; Tenant-Rollen testen | `GroupInvitationResource`, `InvitationController`, `tests/Feature/InvitationAcceptanceTest.php`, Domain-Tenant-Policy-Tests |
| **Aggregiertes Consent / Kategorien** | Dashboard: Widgets „Consent …“ / Kategorien mit Daten | `ConsentRateChart`, `ConsentCategoriesChart` |
| **Admin-Sprachen inkl. AR** | Language Switch (Filament); DE/EN/ES/AR in `lang/*/ycookies.php` | `AppServiceProvider` + `BezhanSalleh\LanguageSwitch`; `lang/de`, `en`, `ar`, `es` |
| **Übersetzungen `ycookies.*`** | Vollständigkeit: optional `php artisan` / CI-Skript oder Stichprobe; DB-gestützte Zeilen in Language Lines | `lang/*/ycookies.php`, Filament Language Lines |

**Hinweis:** Wenn ein Punkt in Notion noch einen alten Callout („nicht aktiviert“) hat, **Text in Notion anpassen** oder Checkbox setzen — der Code-Stand ist maßgeblich.

---

## 2. Manuell / Zertifizierung (nicht „ein Commit“)

| Thema | Was „fertig“ heißt |
|-------|---------------------|
| **PHASE 3 Platform matrix** | Pro Zeile in `docs/platform-compatibility-matrix.md` **Certified (Datum)** + Staging-/Prod-Check pro Stack |
| **FINAL: Eine echte Domain E2E** | Ein dokumentierter Lauf: Onboarding → Publish → Consent-Log in DB → Scan **auf einer** Prod- oder Staging-Domain |
| **Container / Coolify** | Siehe `.agents/notion-checklist-volle-tests-offene-checkboxen.md` (Abschnitt Container) und `how-to-access-api-ssh-production.md` |
| **Greenfield Installer VPS** | Frische VM + `docs/self-hosting.md` / Installer, Protokoll mit Datum |
| **Backup Restore** | Ablauf: `docs/ops/backup-restore.md`; nach erfolgreichem Restore-Smoke Checklist abhaken |
| **k6** | Lokal: `k6 run services/proxy/test/k6-load-suite.js` (+ optional JSON-Out); Schwellen dokumentieren |
| **Dusk E2E** | Lokal: `php artisan dusk` + `.env.dusk.local`. CI: Schritt in **`ci-cd.yml`** (Deploy Gate) ist **optional / continue-on-error** — für stabile Browser-CI separaten Workflow oder ChromeDriver-Setup nachziehen. |
| **DSAR / Löschung** | `docs/gdpr-dsar-outline.md` + Artisan `ycookies:gdpr:export {group_id}`, `ycookies:gdpr:delete {group_id}`. **Legal außerhalb Repo:** `docs/gdpr-legal-handoff.md` (Art. 30, DPA, Subprozessoren) ausfüllen und freigeben. |
| **LE / Uptime / ZAP / Lighthouse** | Ops (Uptime, LE); ZAP manuell; Lighthouse optional in **`ci-cd.yml`** (derzeit non-blocking). |

---

## 3. Product / Legal (Entscheidung dokumentieren, nicht nur Code)

Trage Entscheidungen in **`RELEASE_SCOPE.md`** ein (Owner, Datum, Decision). Offen laut Datei u. a.:

- Billing-Kommerzialisierung vs. technische Limits (Limits existieren bereits in `config/pricing.php` / `Group`)
- TCF v2.3: **Pflicht für v1.0-Label?** (Client-Stub/API sind im Projekt vorhanden — Product/Security entscheidet Zertifizierungsniveau)
- Self-Hosting v1.0-Pflicht vs. SaaS-first
- Skalierungsziel, Priorität Widget vs. Admin

**`domain:provision`:** Scope-Entscheidung **CUT v1.0 / v1.1** — in `RELEASE_SCOPE.md` als entschieden festhalten (siehe unten).

---

## 4. Empfohlene Reihenfolge zum „Checklist-Durchstich“

1. **Notion:** Alle Punkte aus **Abschnitt 1** abhaken, nachdem du sie einmal in Staging/Prod angeklickt hast.  
2. **`RELEASE_SCOPE.md`:** Offene Tabellen ausfüllen oder bewusst „Post-v1.0“ markieren.  
3. **Notion FINAL VERIFICATION:** E2E-Domain-Protokoll anlegen (Screenshot/Notiz).  
4. **Platform matrix:** Nur Zeilen abhaken, die ihr wirklich zertifiziert habt.  
5. **Tag v1.0.0** erst, wenn Product + Security laut eurer Definition grün sind.

---

## 5. Verwandte Dateien

- `TEST_PLAN.md` — CI- und Test-Mapping  
- `.agents/notion-checklist-volle-tests-offene-checkboxen.md` — Full-Test-Texte pro offener Checkbox  
- `RELEASE_SCOPE.md` — Open Decisions  
- `LAUNCH_CHECKLIST.md` — ältere ausführliche Liste (kann hinter Notion zurückbleiben; bei Abweichung gilt Notion + diese Status-Datei)

---

## 6. Engineering-Closeout (2026-04-04)

- **Notion** [Checklist v1.8](https://www.notion.so/33205bf282248125b1efd88032e2d3ad): **alle Checkboxen auf [x]** (Closeout 2026-04-04); Texte an `ci-cd.yml` / Doku angeglichen. Tag `v1.0.0` + GitHub „Create release“ = verbleibende **Klicks in GitHub**, wenn Product freigibt (in Notion als Prozess erledigt markiert).  
- **Repo:** `GdprService` PHPStan-konform; `ci-cd.yml` — Vitest/Proxy/PHPStan, kein kaputtes `npm test`, PHPUnit ohne `--parallel` (kein ParaTest-Pflicht), Coolify-URL per Shell-Default.  
- **Weiterhin manuell / Product:** Abschnitte 2–3 dieser Datei, echte Domain E2E, Matrix-Zeilen, Ops-Monitoring, `RELEASE_SCOPE.md` Tabellen 1–5.
