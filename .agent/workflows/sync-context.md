---
description: Sync ai-help context docs with recent code changes
---

# /sync-context — Context Documentation Sync (v2)

Detects code changes since last sync, maps them to specific doc sections via the manifest, reviews evidence, patches minimally, and logs the outcome.

// turbo-all

## Rules

1. **Evidence rule**: Only update docs based on changed code, tests, config, or explicit implementation notes. Do not infer behavior changes from filenames, import reordering, or formatting-only diffs. A matched diff triggers review of the mapped section, **not a presumption that the documented behavior changed**.
2. **Minimal patching**: For docs marked `protected: true`, patch only the targeted sections. Never rewrite the whole file unless explicitly requested.
3. **Marker advancement**: Advance `last_synced_commit` on every completed review cycle (`no_relevant_changes`, `docs_still_accurate`, `docs_updated`). Do NOT advance when outcome is `manual_review_required` or the run failed. Write all doc updates and changelog BEFORE advancing.
4. **Section deduping**: After mapping resolution, group review work by `(doc_path, section_heading)`. If multiple mappings target the same section, aggregate all evidence and patch once.
5. **Test-only changes**: For mappings with `sync_policy: "test_only"` or when only test files changed — review invariants for newly documented contracts or bug classes. Update docs only if the test reveals a previously undocumented invariant. Otherwise log as `docs_still_accurate`.

## Outcomes

Every run MUST end with exactly one of:

- **`no_relevant_changes`** — Changed files don't match any mapping
- **`docs_still_accurate`** — Matched mappings reviewed, docs are still correct
- **`docs_updated`** — One or more docs were patched
- **`manual_review_required`** — Cannot safely determine if docs are accurate (e.g., major architectural change, missing doc, deprecated mapping)

---

## Steps

### 0. Resolve repository root

Do NOT hardcode paths. Resolve the repo root dynamically:

```powershell
git rev-parse --show-toplevel
```

Use this as `$REPO` for all subsequent commands.

### 1. Determine comparison range

Read `$REPO/ai-help/context-sync.json` → `sync_state.last_synced_commit`.

**If null (first run):**

- This is **full audit mode**
- Review ALL mappings against current doc content
- Do not diff — read each mapped source file and verify its docs

**If set:**

- Verify the commit still exists:

```powershell
git -C $REPO rev-parse --verify <last_synced_commit>
```

- If invalid: fall back to `HEAD~5` and flag `manual_review_required`
- If valid: proceed with diff

### 2. Get changed files

```powershell
git -C $REPO diff --name-status <from_commit>..HEAD
```

Use `--name-status` to detect renames (R status). For renames, match against BOTH old and new paths.

Normalize all paths with forward slashes before matching.

### 3. Match against manifest

For each changed file, check against all mappings in `context-sync.json`:

- Use **gitignore-style glob** matching (e.g., `app/Jobs/**` matches `app/Jobs/Foo/Bar.php`)
- `**` matches any depth, `*` matches within one path segment
- Collect all matched mapping IDs with their risk levels and doc targets

If no mappings match → outcome is `no_relevant_changes`. Skip to step 6 (marker advances since review completed successfully).

Sort matched mappings by risk: `critical` → `high` → `medium` → `low`.

### 3b. Deduplicate by (doc, section)

Multiple mappings may target the same doc section. Before reviewing:

1. Build a map of dedupe keys → `[list of triggering mappings + changed files]`:
   - If a mapping has `sections: ["Step 10", "CSP Nonce"]` → keys are `(doc_path, "Step 10")` and `(doc_path, "CSP Nonce")`
   - If a mapping has `sections: null` → key is `(doc_path, "__FULL_DOC__")`
2. Review and patch each unique key exactly once, using aggregated evidence from all contributing mappings
3. Log all contributing mappings in the changelog entry

### 4. Review each unique doc target

For each unique review target produced in Step 3b:

- If the target is `(doc_path, section_heading)`, review that section once using aggregated evidence.
- If the target is `(doc_path, "__FULL_DOC__")`, review the full document once using aggregated evidence.

**4a. Check doc exists:**

- If doc file is missing → log `REVIEW_NEEDED: doc missing` → do NOT update marker
- If targeted section heading is missing in the doc → append content under a new heading at the appropriate location

**4b. Read the evidence:**
- Read the changed source files (the actual diff or current content)
- Read the targeted doc section(s)
- Determine: did the code change alter the behavior, contract, or invariant described in that section?

**4c. Classify the change:**

| Evidence | Action |
|----------|--------|
| Formatting only, no behavior change | Skip — `docs_still_accurate` |
| New capability added | Patch the section to include it |
| Existing behavior changed | Patch the section to reflect new behavior |
| Behavior removed or deprecated | Mark with `[DEPRECATED]` tag, do not delete |
| Cannot determine from code alone | Flag `manual_review_required` for this section |

**4d. If doc is `protected: true`:**
- Only edit the specific section(s) listed in the mapping
- Do not touch other sections of the file
- Use `replace_file_content` targeting the section, not `write_to_file` overwrite

**4e. If mapping has `sync_policy: "test_only"`:**
- Review if the test documents a new invariant or bug class
- If yes: update the relevant invariants/risk section
- If no (coverage-only): skip edit, note as `docs_still_accurate`

### 5. Write changelog entry

Append to `ai-help/context/CHANGELOG.md` using this strict schema:

Two valid header formats:

**Normal sync:**
```markdown
## YYYY-MM-DD — Sync from `<from_commit_short>` to `<to_commit_short>`
```

**Full audit (first run):**
```markdown
## YYYY-MM-DD — Full audit at `<to_commit_short>`
```

Body schema:

```markdown
**Outcome**: `no_relevant_changes` | `docs_still_accurate` | `docs_updated` | `manual_review_required`

### Changed path groups
- `proxy-core` (critical) — server.js, html-injector.js
- `consent-widget` (high) — manager.js

### Docs reviewed
| Doc | Section | Action |
|-----|---------|--------|
| `02-request-lifecycle.md` | Step 10 — HTML Transform Pipeline | Updated |
| `03-risk-and-invariants.md` | Proxy Invariants | No change needed |
| `01-product-and-architecture.md` | Consent Model | No change needed |

### Unresolved
- None
<!-- OR -->
- `04-ops-and-rollback.md` section "Rollback Procedure" — needs manual review (major Docker changes)
```

### 6. Update sync marker

**Advance the marker for all outcomes EXCEPT `manual_review_required`.**

If outcome is `no_relevant_changes`, `docs_still_accurate`, or `docs_updated`: update `ai-help/context-sync.json`:

```json
{
  "sync_state": {
    "last_synced_commit": "<HEAD full hash>",
    "last_synced_at": "<ISO 8601 timestamp>",
    "last_outcome": "docs_updated"
  }
}
```

If outcome is `manual_review_required`: do NOT update the marker. The next run will re-process these commits.

Get the current HEAD:

```powershell
git -C $REPO rev-parse HEAD
```

### 7. Report to user

Summarize:
- **Outcome**: one of the four states
- **Commits compared**: `<from>` → `<to>`
- **Mappings triggered**: count and labels
- **Docs updated**: list with sections
- **Docs unchanged**: list
- **Manual review needed**: list with reasons
- **Risk assessment**: any critical-risk changes that were auto-patched
