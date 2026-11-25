# PipeDoc IQ — User Manual

## Concepts
* **Engineering Database** — your verified dimensional data. Nothing is invented; missing values stay blank and are flagged. Each record stores unit, source reference, standard revision and verification status; edits keep a version history.
* **Heat numbers** — traceability records with chemical/mechanical tests. Use ✓ to mark *verified*. Duplicating a heat creates an *unverified* copy.
* **Templates** — your own DOCX/XLSX formats containing `{{placeholders}}`. Rows with item placeholders repeat per item (DOCX).
* **Documents** — generated as *draft* → *pending review* → *approved* → *issued* (locks the snapshot). Changes after issue = new revision.

## Typical workflow
1. **Import / Export**: pick the dataset, upload CSV/JSON/XLSX, check the column mapping, choose duplicate handling, import. Header templates are in `samples/`.
2. **Template Studio**: upload a template → review detected placeholders → map to database fields → *Save & mark verified* (reusable without remapping) → position stamp/signature.
3. **Document Generator**: follow the 10 steps; warnings (unverified heat, missing tests, missing weights) are shown before generation.
4. **Document Archive**: preview/print (A4), download DOCX or PDF (needs LibreOffice), revise, approve, issue, archive/restore.

## Smart search
Type descriptions like `100NB CL150 FLANGE WELDNECK RAISED FACE FLANGE (WNRF)`. Recognised: product, NB/NPS, class, facing, schedule, SW/BW/LR/SR, CS/SS/AS, ASTM grade tokens. If several variants match you must confirm.

## Calculations (deterministic)
Inside diameter = OD − 2·WT. Weight/m = π·(OD−WT)·WT·ρ/10⁶ (ρ default 7850 kg/m³ for steel; use the grade's density when known) — approximate, nominal wall. Totals, tolerance and limit checks, PO balance, unit conversions. Actual results never auto-filled: no actual value ⇒ *pending*.

## Gemini AI (optional)
Gemini AI Settings → paste key → Test connection → choose a model → tick consent → enable. All calls go through PHP. AI only converts text to parameters / suggests field mappings; it never supplies technical values.

## Limits in this release (honest list)
* Stamp/signature positions are stored but **not yet embedded** into generated DOCX/XLSX.
* XLSX templates: single-value placeholders only (no repeating rows).
* Combined PDF download, background queue for huge jobs and DOC/XLS legacy conversion are not implemented yet (PDF per document requires LibreOffice).
* Authentication/roles are deliberately absent (WordPress admin only).
