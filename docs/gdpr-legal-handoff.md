# DSGVO — Legal-Handoff (Art. 30, DPA, Unterauftragsverarbeiter)

> **Keine Rechtsberatung.** Diese Datei ist eine **Arbeitsvorlage** für eure Juristen: Tabellen und Checklisten zum Ausfüllen, Abgleich mit dem Produkt und Veröffentlichung **außerhalb** des Repos (Verzeichnis intern, DPA-PDF, Webseite).

**Technik / Betrieb (im Repo):** [DSAR & Löschung](gdpr-dsar-outline.md) · Artisan `ycookies:gdpr:export|delete {group_id}` · Backup/Restore: [ops/backup-restore.md](ops/backup-restore.md)

---

## 1. Rollenmodell (von Legal bestätigen)

| Frage | Typische Einordnung (nur Diskussionsgrundlage) |
|--------|--------------------------------------------------|
| Wer ist **Website-Besucher**-bezogen Verantwortlicher? | In der Regel der **Kunde** (Tenant), der YCookies auf seiner Site einbindet. |
| Rolle von YCookies (SaaS-Betreiber)? | Häufig **Auftragsverarbeiter** gem. Art. 28 DSGVO für die vom Kunden gesteuerten Verarbeitungen (Consent, Logs, Konfiguration). **Eigenständige** Verarbeitungen (z. B. **Admin-Accounts**, **Abrechnung**, **Support-Mail**) können zusätzlich als Verantwortlicher oder mit separater Rechtsgrundlage laufen — **Legal klärt verbindlich**. |
| Self-Hosting | Wenn der Kunde die Software selbst betreibt, verschieben sich Rollen und AV-Vertragspflicht — **separate Vorlage**. |

---

## 2. Verzeichnis der Verarbeitungstätigkeiten (Art. 30 Abs. 1 DSGVO) — Vorlage

**Anwendung:** In euer **offizielles** Verzeichnis (Notion/Excel/Dokumentenmanagement) übernehmen und **Rechtsgrundlagen, Fristen und Empfänger** juristisch festlegen.

### 2.1 Verarbeitung im Auftrag des Kunden (typisch CMP / Consent)

| Nr. | Zweck der Verarbeitung | Kategorien betroffener Personen | Kategorien personenbezogener Daten | Empfänger / Kategorien von Empfängern | Drittland­transfer | Geplante Löschfristen | TOM (Verweis) |
|-----|-------------------------|----------------------------------|-------------------------------------|----------------------------------------|---------------------|------------------------|---------------|
| V1 | Nachweis und Auswertung von **Einwilligungen / Einwilligungsständen** für vom Kunden konfigurierte Cookie-/Marketing-Zwecke | Endnutzer der Kunden-Websites | Pseudonyme Kennung (`consent_uid`), **gehashter** IP-Bezug (`ip_hash`, kein Roh-IP in DB), ggf. gekürzter **User-Agent**, Consent-JSON, ggf. **TC String** (IAB TCF), Cookie-Version | Hosting des SaaS (Betreiber), ggf. **Subprozessoren** (s. Abschnitt 4) | *Legal: ja/nein + Mechanismus* | Retention pro Tenant / Produktkonfiguration + Purge-Job; **Legal** setzt Mindest-/Höchstfrist | Abschnitt 3 / Anlage DPA |
| V2 | **Bereitstellung des CMP** (Banner, Blocker-Logik, Proxy-Modus): Auslieferung von Konfiguration und Skripten | Endnutzer | Technisch: angefragte URLs, ggf. RUM-/Metriken je nach Konfiguration — **Umfang von Ops/Produkt gegenprüfen** | Wie V1 | *Legal* | Entsprechend Log-/Cache-Politik der Infra | Abschnitt 3 |

### 2.2 Eigene Geschäftsverarbeitungen (Betreiber YCookies)

| Nr. | Zweck | Betroffene | Daten | Empfänger | Drittland | Löschfrist | TOM |
|-----|--------|------------|-------|-----------|-----------|------------|-----|
| B1 | **Admin-Zugang** (Filament), Rollen, Mandantenfähigkeit | Mitarbeiter des Kunden, ggf. Agentur-Nutzer | Name, E-Mail, Passwort-Hash, Rollen, Gruppenzugehörigkeit | Hosting, ggf. E-Mail-Provider | *Legal* | Nach Vertragsende / Konto-Löschregel — **Policy** | Abschnitt 3 |
| B2 | **Abrechnung** (Stripe/Cashier) | Zahlungspflichtige Kontakte des Kunden | Zahlungs- und Vertragsstammdaten bei Stripe | **Stripe** (Subprozessor) | *Legal (SCC o. Ä.)* | Nach Steuer-/Handelsrecht + Stripe-Richtlinie | Stripe-DPA |
| B3 | **E-Mail** (Verifizierung, Reset, Einladungen, Benachrichtigungen) | Nutzer wie B1 | E-Mail-Inhalt, Metadaten | Transactional-Mail-Anbieter | *Legal* | Nach Zweckerfüllung | Vertrag + TLS |
| B4 | **Fehleranalyse / Monitoring** (z. B. GlitchTip/Sentry) | ggf. technische Metadaten, in Ausnahmefällen personenbezogene Reste in Stacktraces | Konfiguration abhängig | Monitoring-Anbieter | *Legal* | Anbieter-Retention | Minimierung, Scrubbing-Policy |
| B5 | **Scanner** (optional) | Konfiguration des Kunden | URLs, ggf. Seiteninhalte zur Kategorisierung | interne Verarbeitung auf Infra des Betreibers | *Legal* | Logs gemäß Ops-Policy | Zugriffsbeschränkung |

**Hinweis Consent-Log (Code-Stand):** Felder u. a. `consent_uid`, `ip_hash`, `user_agent`, `consents_granted`, `services_granted`, `tc_string` — vollständiges Schema siehe Migrationen / Modell `ConsentLog`.

---

## 3. Technisch-organisatorische Maßnahmen (TOM) — Kurztext für DPA-Anlage / Art. 32

**Von Legal in den AV-Vertrag übernehmen und ggf. spezifizieren.**

- **Zugangskontrolle:** Authentifizierung Admin, Rollen (Filament), Mandantenisolation auf Applikationsebene.
- **Zugriffskontrolle:** Least-Privilege für Ops; SSH/Panel nach interner Policy.
- **Trenngebot:** Tenant-Daten modellseitig über `Group` / Domains abgegrenzt (Export/Löschung pro Gruppe möglich).
- **Weitergabekontrolle:** TLS für Transport; Webhooks/ APIs nur nach Konfiguration des Kunden.
- **Eingabekontrolle:** Validierung API (z. B. Consent-Ingest); Rate-Limits (s. `AppServiceProvider`).
- **Auftragskontrolle:** Subprozessoren nur nach Liste / Weisung (s. Abschnitt 4).
- **Verfügbarkeit:** Backups (s. Spatie / Runbook), Redis/MySQL-Konfiguration je nach Deployment.
- **Verfahren zur Wiederherstellung:** [backup-restore.md](ops/backup-restore.md).
- **Pseudonymisierung:** `ip_hash` statt Roh-IP in Consent-Logs.

*Hosting-Standort, Verschlüsselung at-rest (DB/Volume), SIEM — von Ops konkret benennen und hier ergänzen.*

---

## 4. Verzeichnis der Unterauftragsverarbeiter (Art. 28 Abs. 2) — Vorlage

**Tabelle für Webseite oder DPA-Anhang.** Namen und Zwecke von **Legal und Ops** verifizieren.

| Subprozessor (Name, Anschrift) | Zweck | Art der Daten | Standort Region | Drittland-Mechanismus |
|--------------------------------|--------|---------------|-----------------|------------------------|
| *z. B. Hetzner / AWS / …* | Hosting App + DB | Alle Produktionsdaten | *EU / …* | *n. z. / SCC / …* |
| *Stripe* | Zahlungen | Zahlungs- und Kundendaten | *wie Stripe-DPA* | *SCC o. Ä.* |
| *E-Mail-Provider* | Transaktionsmail | E-Mail, ggf. Name | *…* | *…* |
| *GlitchTip / Sentry / …* | Error Tracking | technische Fehlerdaten | *…* | *…* |
| *Coolify / Managed Kubernetes* | Deployment | Konfiguration, Secrets | *…* | *…* |

Änderungen: **Vorankündigung** gem. Art. 28 Abs. 2 S. 2 DSGVO und Vertrag mit dem Kunden abstimmen.

---

## 5. Auftragsverarbeitungsvertrag (Art. 28) — Checkliste für Legal

- [ ] **Gegenstand und Dauer** der Verarbeitung (Leistungsbeschreibung YCookies SaaS).
- [ ] **Art und Zweck** (s. Tabelle 2.1).
- [ ] **Obliegenheiten des Verantwortlichen** (Weisungen, Rechte der Betroffenen, Freigabe Subprozessoren).
- [ ] **Pflichten des Auftragsverarbeiters** (Art. 28 Abs. 3) — Standardklauseln / Muster AV-Vertrag.
- [ ] **Anlage:** TOM (Abschnitt 3).
- [ ] **Anlage:** Liste Unterauftragsverarbeiter (Abschnitt 4) + Änderungsverfahren.
- [ ] **Löschung / Rückgabe** nach Vertragsende (inkl. Backups — Fristen mit Ops abstimmen).
- [ ] **Benachrichtigung** bei Datenschutzvorfällen (Ansprechpartner, Fristen).
- [ ] **Hilfe bei DSAR** (Prozess: [gdpr-dsar-outline.md](gdpr-dsar-outline.md)).
- [ ] **Nachweis** Audits / Zertifikate falls angeboten.

Für **UK / CH / US-Kunden** ggf. **Zusatzvereinbarungen** (UK IDTA, SCC Module) — Legal.

---

## 6. DPA für **Endkunden des SaaS-Kunden**?

Die **Privacy Policy / Cookie-Hinweise** auf der **Website des Kunden** regeln die Beziehung Kunde → Besucher. YCookies stellt in der Regel nur die **Software**. Ob ihr eine **Muster-Privacy-Klausel** für Einbindung des CMP anbietet, ist **Marketing/Legal** — nicht in diesem Repo.

---

## 7. Freigabe-Log (intern)

| Version | Datum | Freigegeben durch (Rolle) | Änderung |
|---------|--------|---------------------------|----------|
| 1.0 | *…* | *Legal* | Erstveröffentlichung Verzeichnis / DPA |

Wenn **Art. 30** und **DPA** extern final sind, kann der Launch-Checklist-Punkt „Legal Art. 30 / DPA“ intern als **erledigt (Datum + Link)** dokumentiert werden.
