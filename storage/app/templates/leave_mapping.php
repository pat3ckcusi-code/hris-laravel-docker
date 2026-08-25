<?php

/**
 * Coordinate map for stamping data onto the real official CS Form 6 PDF
 * (storage/app/templates/LEAVE.pdf) via FPDI, from
 * LeaveRequestService::buildEsignaturePdfBytes(). PDF points, bottom-left
 * origin, matching FPDI/FPDF's own coordinate convention for this template
 * (page 1 is 595.32 x 841.92pt / A4).
 *
 * Every coordinate here was measured directly against the real LEAVE.pdf's
 * own content stream (Tm text-position operators and `re` rectangle/checkbox
 * borders), not guessed - see the plan file for the extraction method. Two
 * conventions used throughout, both confirmed against real elements on this
 * specific form:
 *  - A single-line labeled field (e.g. "3. DATE OF FILING") has its blank
 *    answer area ABOVE the label, not beside it - confirmed via the
 *    applicant-signature area, which sits in a blank gap above its own
 *    "(Signature of Applicant)" caption with no printed line at all.
 *  - A checkbox is a small (~10.5x10.5pt) hand-drawn box; its true center is
 *    consistently ~2.3pt above the row label's own baseline y (measured from
 *    13 matching left-column leave-type checkboxes).
 *
 * Not exhaustive - low-frequency fields (rejection notes, disapproval
 * detail) are approximated more loosely than high-frequency ones (name,
 * leave type, dates, VL/SL balance, signature). Expect real-world tweaks
 * here after the user visually reviews an actual generated PDF - that's a
 * coordinate edit in this one file, not a design change.
 *
 * Every `y` below is a true baseline again as of 2026-08-21:
 * LeaveRequestService::buildEsignaturePdfBytes()'s $write()/$mark() closures now
 * compensate for FPDF's own Write()/Cell() positioning (which places text by
 * roughly the top of the line box, not the baseline - confirmed empirically as
 * a fixed `0.5*5 + 0.3*fontSize` points-too-low offset, see that method's own
 * comment). Before that fix, several entries here briefly carried a manually
 * pre-compensated (i.e. deliberately wrong-looking) `y` to counteract the bug
 * by hand - those have all been reverted back to their real measured value now
 * that the rendering code corrects for it once, centrally.
 */
return [
    // --- Header / personal info (label y, value placed just above it) ---
    // Moved down 2026-08-21 (y=700 -> 694, explicit user request) to sit closer to the
    // real border line separating this row ("1. OFFICE/DEPARTMENT"/"2. NAME") from the
    // "3. DATE OF FILING" row below (a full-width rule at y~691.78-692.50) instead of
    // floating with a larger gap underneath. department's font also dropped 9 -> 7
    // (explicit user request) - department names on this form tend to run long (e.g.
    // "CITY HEALTH & SANITATION DEPARTMENT - NORTH"), and this column's own width
    // (roughly x=40 to the NAME column's start at x=213.77) is tighter than a full-name
    // value needs at 9pt.
    'full_name' => ['x' => 290, 'y' => 694, 'font' => 'Arial', 'size' => 9, 'bold' => true],
    'department' => ['x' => 40, 'y' => 694, 'font' => 'Arial', 'size' => 7, 'bold' => true],
    // Moved down 2026-08-21 (y=682 -> 676, explicit user request) to sit closer to each
    // field's own real underline (short vector rules at y~673.03-673.75, one per field:
    // x=[130.73,212.33] date_filed, x=[268.51,376.17] position, x=[423.22,493.80] salary)
    // instead of floating higher with a bigger gap above them. 676 lands just ~2pt above
    // those rules, and happens to closely match "3./4./5."'s own label baseline (676.03) -
    // this row's labels and their blanks sit close together (unlike, say, the paid_days/
    // signatory pattern elsewhere in this file, where the blank is a full line below).
    //
    // salary's x corrected 380 -> 425 same day (found while re-measuring the above): its
    // real blank starts at x=423.22, not anywhere near the old x=380, which actually sat
    // under "5.   SALARY"'s own label text (starts x=377.62) - so the value was printing
    // on top of the label, not on the blank after it. Not something you flagged, but the
    // same class of bug as paid_days' original x=40, caught while touching this row.
    'date_filed' => ['x' => 130, 'y' => 676, 'font' => 'Arial', 'size' => 9],
    'position' => ['x' => 270, 'y' => 676, 'font' => 'Arial', 'size' => 9],
    'salary' => ['x' => 425, 'y' => 676, 'font' => 'Arial', 'size' => 9],

    // --- 6.A Leave type checkboxes (left column, x=48 center) ---
    'leave_type_coords' => [
        'vacation leave' => [44, 627], 'vl' => [44, 627],
        'mandatory/forced leave' => [44, 614], 'mandatory leave' => [44, 614], 'forced leave' => [44, 614],
        'sick leave' => [44, 600], 'sl' => [44, 601],
        'maternity leave' => [44, 587],
        'paternity leave' => [44, 574],
        'special privilege leave' => [44, 560], 'spl' => [44, 562],
        'solo parent leave' => [44, 545],
        'study leave' => [44, 536], 'study / examination leave' => [44, 536],
        '10-day vawc leave' => [44, 523], 'vawc leave' => [44, 523], 'vawc' => [44, 523],
        'rehabilitation privilege' => [44, 510],
        'special leave benefits for women' => [44, 497], 'special leave (gynecological)' => [44, 497],
        'special emergency (calamity) leave' => [44, 484], 'calamity leave' => [44, 484],
        'adoption leave' => [44, 471],
        'wellness leave' => [100, 433], 'wlns' => [100, 433], 'others' => [100, 433],
    ],
    // "Others"/"Wellness" free-text label, written on the blank after "Others:" (46.70, 431.21)
    // 'others_area' => ['x' => 90, 'y' => 428, 'w' => 200, 'h' => 8],
    // --- 6.B Details of Leave (right column marks, x=304 center) ---
    'purpose_marks' => [
        'within_the_philippines' => [298, 613],
        'abroad' => [298, 597.9],
        'in_hospital' => [298, 572],
        'out_patient' => [298, 556.9],
        'women_illness' => [298, 516.3],
        'study_completion' => [298, 474.8],
        'bar_review' => [298, 460.9],
        'monetization' => [298, 433.2],
        'terminal_leave' => [298, 419.5],
    ],
    // Specify-illness / specify-place free text, written on the blank line after each mark's
    // label - 'specify_illness_coords' (above) is a leftover from the dead-code
    // generatePdfResponse()/Leave_Form.pdf path (see that method's own docblock) and was
    // never actually consumed by buildEsignaturePdfBytes(), the live e-signature PDF path -
    // that's why an employee's Specify Illness text never appeared on a real generated PDF
    // despite being collected at filing (details_sick_illness) and present in the Excel
    // build (K19/K21). Added 2026-08-21 as flat top-level keys (matching this file's
    // general convention, not the dead code's nested array) so $write() can reach them
    // directly. x is each label's own real text width (FPDF::GetStringWidth() at Arial
    // 7.32pt, this template's own label font/size) measured from where the label starts
    // (x=310.87, same row) to where its underscore blank begins - "In Hospital (Specify
    // Illness) " = 90.31pt wide, "Out Patient (Specify Illness)  " (two trailing spaces,
    // matching the real template text) = 94.38pt wide - not a visual guess, since
    // FPDI/Write() left-aligns from x with no way to flow text after existing template
    // content. y matches each row's own real label baseline (568.27/554.59) - this is the
    // same "inline value on the label's own baseline" convention paid_days/lwop_days use,
    // not the separate "value above a line" convention used elsewhere in this file, since
    // this blank continues the SAME printed line rather than sitting on a line by itself.
    'specify_illness_in_hospital' => ['x' => 401.18, 'y' => 568.27, 'font' => 'Arial', 'size' => 8],
    'specify_illness_out_patient' => ['x' => 405.25, 'y' => 554.59, 'font' => 'Arial', 'size' => 8],

    // --- 6.C / 6.D working days, inclusive dates, commutation ---
    // Moved down 2026-08-21 (explicit user request) to sit closer to each field's own
    // real underline (found via vector geometry): total_days' blank is x=[55.70,212.32]
    // y=[379.37,380.09], period's is x=[55.70,212.32] y=[355.73,356.45] - both ~2pt below
    // the new values, matching the small-margin convention used throughout this file.
    'total_days' => ['x' => 130, 'y' => 382, 'font' => 'Arial', 'size' => 9, 'bold' => true],
    'period' => ['x' => 85, 'y' => 358, 'font' => 'Arial', 'size' => 9], // inclusive dates, same line as "INCLUSIVE DATES" label
    // 'commutation_not_requested' => [304, 388.2],

    // --- 7.A Certification of Leave Credits (VL/SL grid; row label y, columns under headers) ---
    // Moved down 5pt 2026-08-21 (307 -> 302, explicit user request - was crowding
    // "7.A CERTIFICATION OF LEAVE CREDITS" above it at baseline 312.98). Still sits
    // comfortably above its own "As of ___" line (baseline 299.90).
    'approved_at' => ['x' => 135, 'y' => 302, 'font' => 'Arial', 'size' => 8], // "As of ___" date
    // Grid values corrected 2026-08-21 to match each row's own label baseline exactly
    // ("Total Earned" 276.14, "Less this application" 266.30, "Balance" 256.46 - all
    // stable across every template edit today). The previous 280/270/260 predate this
    // session's $write() baseline-offset fix and were never actually re-measured against
    // the real content stream despite this file's own top docblock - they happened to
    // look approximately right only because the old bug coincidentally rendered them
    // ~5pt lower, landing close to (not exactly on) each row's own baseline. Once the
    // offset bug was fixed, that accidental compensation went away and the true ~3.5-3.9pt
    // error became visible as the value crowding/overlapping the grid line above its own
    // row. Confirmed via a real render.
    'vl_total_earned' => ['x' => 155, 'y' => 276.14, 'font' => 'Arial', 'size' => 9],
    'vl_requested' => ['x' => 155, 'y' => 266.30, 'font' => 'Arial', 'size' => 9],
    'vl_balance' => ['x' => 155, 'y' => 256.46, 'font' => 'Arial', 'size' => 9],
    'sl_total_earned' => ['x' => 227, 'y' => 276.14, 'font' => 'Arial', 'size' => 9],
    'sl_requested' => ['x' => 227, 'y' => 266.30, 'font' => 'Arial', 'size' => 9],
    'sl_balance' => ['x' => 227, 'y' => 256.46, 'font' => 'Arial', 'size' => 9],

    // --- 7.B Recommendation ---
    'recommend_approval' => [350, 160, 'bold' => true],
    'recommend_disapproval' => [350, 160],
    'disapproval_reason' => ['x' => 320, 'y' => 286.22, 'font' => 'Arial', 'size' => 8],

    // --- 7.C / 7.D Approved for / Disapproved due to ---
    // The LEAVE.pdf template has been edited (by hand, outside this codebase) three
    // times in one day (2026-08-21) so far, each time inserting more vertical space
    // somewhere in the 7.A/7.B block and pushing everything below it further down -
    // these values are re-measured against the current file each time, not derived
    // by arithmetic from the previous round's numbers, since the amount of movement
    // hasn't been consistent from edit to edit. Re-check against a fresh text-span
    // extraction (PyMuPDF, origin-based baseline) before trusting these if the
    // template changes again.
    'paid_days' => ['x' => 62, 'y' => 161.06, 'font' => 'Arial', 'size' => 9],
    'lwop_days' => ['x' => 62, 'y' => 150.36, 'font' => 'Arial', 'size' => 9],
    'disapproved_due_to' => ['x' => 320, 'y' => 160.22, 'font' => 'Arial', 'size' => 8],

    // --- Signatories ---
    // Department Head recommendation - no explicit printed line found near the
    // leave-type/recommendation area for this on page 1's extracted layout;
    // placed near the recommendation section as the best available anchor (this
    // is a floating placement by design, not tied to a printed line - unlike
    // every other signatory below). Deliberately kept on the same row as
    // hr_manager_designation (a different column, but visually reads as one
    // aligned baseline across the page) - re-derive to match that field's own
    // value if it ever moves (moved with it 2026-08-21: 200.4 -> 195.4).
    'department_head' => ['x' => 330, 'y' => 195.4, 'font' => 'Arial', 'size' => 9],

    // Executive (Mayor/Vice-Mayor) signatory. CORRECTED 2026-08-21: previously
    // anchored to the outer page/box border near the bottom of the form, on the
    // mistaken assumption (from this field's original build, before any of this
    // session's fixes) that it was a real printed "Mayor" signature rule - it
    // isn't, it's just the structural bottom edge of the whole "7. DETAILS OF
    // ACTION ON APPLICATION" box, and there are no "NAME OF MAYOR / VICE MAYOR"
    // / "DESIGNATION" captions anywhere on this template (confirmed absent by
    // direct text extraction - an earlier version of this comment cited specific
    // coordinates for those captions, which described a different, older
    // template version, not this one). The REAL dedicated signature line for
    // this slot is a short vector rule inside the 7.C/7.D box itself, under
    // "_______ others (Specify)", roughly centered under the 7.C column
    // (x=[181.68,339.66]) - found by rendering the current blank template and
    // looking directly at it, not by more text/vector-coordinate guessing.
    // Semantically this is the "who actually approved this" line (it sits under
    // 7.C APPROVED FOR, not 7.B RECOMMENDATION, which is department_head's own
    // area above), which matches resolveExecutiveSignatory()'s role as the
    // final approving authority.
    'signatory_name' => ['x' => 200, 'y' => 106.36, 'font' => 'Arial', 'size' => 9, 'bold' => true],
    'signatory_designation' => ['x' => 235, 'y' => 96.52, 'font' => 'Arial', 'size' => 8],

    // HR Manager name/designation - stamped onto the real vector-drawn signature
    // line under "7.A CERTIFICATION OF LEAVE CREDITS", directly below the
    // Vacation/Sick Leave grid and above the "7.C APPROVED FOR:" header. That line
    // is a graphic rule, not literal underscore text, so it doesn't show up in a
    // plain text-extraction of the page. This specific line's own position has been
    // stable across every 2026-08-21 template edit so far (unlike the 7.C/7.D block
    // and the Mayor line below it) - re-verify against a fresh extraction if it
    // ever does move, the same way every other field in this section already had
    // to be. Line itself spans x=[55.70,268.63] (rect y=[210.98,212.42], center
    // 211.7).
    'hr_manager_name' => ['x' => 122.39, 'y' => 203.7, 'font' => 'Arial', 'size' => 8, 'bold' => true],
    'hr_manager_designation' => ['x' => 133.97, 'y' => 193.86, 'font' => 'Arial', 'size' => 7],

    // Signature field rect for an HR Manager/Leave Manager's real PNPKI co-signature
    // certifying the leave-credit figures above (7.A CERTIFICATION OF LEAVE CREDITS),
    // consumed the same way as approver_signature_field below - a signature_field.json
    // sidecar for SignESignatureRequestPdfJob's resolveFieldRect(), dispatched from
    // LeaveRequestService::certifyLeaveCredits() via LeaveCertificationController's
    // batch-sign queue (see that controller for why this isn't dispatched at filing
    // time the way the applicant's own signature is). Sits in the blank gap directly
    // ABOVE the hr_manager_name/hr_manager_designation printed line - same
    // "signature above, typed name below" convention the applicant's own
    // "(Signature of Applicant)" caption and approver_signature_field already use
    // relative to their own captions/lines. That line's own rect is y=[210.98,212.42];
    // the next content above it is the VL/SL balance grid at y=256.46
    // (vl_balance/sl_balance) - this box uses the same 37pt height as
    // approver_signature_field, leaving ~2.6pt clear below and ~4.5pt clear above.
    // x-span matches the hr_manager signature line's own x=[55.70,268.63]. Not yet
    // visually verified against a real rendered PDF - re-check and iterate the same
    // way every other field in this file has, per this file's own docblock.
    'hr_certification_signature_field' => ['page' => 1, 'x1' => 55.70, 'x2' => 268.63, 'y1' => 215, 'y2' => 252],

    // Signature field rect for a Department Head/Administrative Officer
    // countersigning a leave at approval (LeaveRequestService::approveLeaveWithEsignature()),
    // consumed as a signature_field.json sidecar by SignESignatureRequestPdfJob's
    // resolveFieldRect() - same mechanism, different reserved area, as the
    // applicant's own DEFAULT_FIELD_RECT in that job.
    //
    // Moved up 2026-08-21 (explicit user request: "above the line") after finding the
    // box was straddling a real static template line - a 4th "For disapproval due to"
    // continuation rule at baseline y=215.06 (x=314.47), directly below 7.B's other
    // three (256.70/266.54/276.38), that this box had been silently overlapping the
    // whole time since it was first placed in this area (found by drawing the box's own
    // outline on a real rendered PDF and looking at it, not by more coordinate math -
    // a normal $write()/$mark() text field would have been visually obvious sitting on
    // top of a line like this, but this box holds an image+text pyHanko stamp that isn't
    // rendered by this file's own preview tooling, so the collision stayed hidden).
    // y1=217.36 sits 2.3pt clear above that line; same 37pt height puts y2=254.36, which
    // in turn sits 2.34pt clear below the next continuation line up (256.70) - tight on
    // both sides since the real gap between those two lines is only 41.66pt, but no
    // tighter than other margins already accepted elsewhere in this file.
    'approver_signature_field' => ['page' => 1, 'x1' => 300, 'x2' => 493, 'y1' => 217.36, 'y2' => 254.36],
];
