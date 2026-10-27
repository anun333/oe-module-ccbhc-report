# Changelog

## 0.1.7 (2026-10-26)

- **The Staff Worklist tab is built on openhealthcare-common's shared contract** (1.5.0), so it loads without the Worklist's classes and has unit tests of its own. Staff see the same "Requests for services" tab with the same rows, on any Worklist from 0.8.0. The bootstrap also registers the library's contracts under their own prefix, so they load where a module copied in by hand carries an older copy that registered first; if no copy with the contracts is there at all, the tab is left out rather than stop the page.
- Requires openhealthcare-common 1.5.0.
- **Checked:** unit tests on PHP 8.2 and 8.5 (35; 3 new: each request without an evaluation is an item, high once past criterion 2.b.1's business days, with the evaluation's categories and CPT codes in the query; one patient's recheck; the tab reaches a Worklist through the contract). `dev/ccbhc_report_check.py` on OpenEMR 8.4.1 with Worklist 0.9.0, and on master with Worklist 0.8.7: today's request is in the tab; all passed.

## 0.1.6 (2026-10-26)

- **Fixed: a date of birth after the day no longer reads as an age.** A birth date entered in the future (2045 typed for 1945, say) made the client that many years old: twenty years late counted as a 20-year-old adult in CDF-AD and SDOH, and 12 or more years late put a client in Time to Services and CDF-CH. Such a client is now age 0 on that day, as one born later that year already was, so the measures leave them out and the case load counts them under 0 to 11. Only a data-entry error reaches this.
- **Every column of a client list download is protected against spreadsheet formulas.** Before, only the chart number and name were; now any text cell that starts with `=`, `+`, `-` or `@` gets an apostrophe, the same rule as the UDS Report's downloads. A chart number like `-12` is a number and no longer gets one.
- **Checked:** the unit tests (32) on PHP 8.2 and 8.5, where the new age test fails on 0.1.5's code; end to end on OpenEMR 8.4.1.

## 0.1.5 (2026-10-26)

- **Faster on years of data.** On a test copy of a mid-size clinic (20,000 patients, 200,000 visits, 15,384 PHQ-9 responses, MariaDB 12.3.3) the 2025 report went from 76 s to 6.7 s, with the same output:
  - **an index on core's `form_questionnaire_assessments.response_id`**, which the deleted-form check joins on and core leaves unindexed (76 s to 45 s). It's added on install and on upgrade (`sql/0_1_4-to-0_1_5_upgrade.sql`), shared by name with the UDS and Title X Report modules, so it's made once;
  - **visits' billing codes read by patient as well as visit** (45 s to 6.7 s): core's `billing` table has an index on the patient but none on the visit, so each lookup of 500 visits read the whole table.
- **Pages are announced in your language.** Each page now declares the signed-in user's OpenEMR language and its direction instead of English, so a screen reader reads the translated text in that language. It comes from openhealthcare-common 1.4.0, which this version requires. If another module's older copy of the library loads first on a site that installs by hand, the pages say English, as before, rather than stop.
- **Manual installs:** the README now says that every module's own copy of openhealthcare-common must be the same version, since OpenEMR loads the library once, from the first module that registers it.

## 0.1.4 (2026-10-26)

- **Fixed: a bipolar diagnosis marked entered-in-error or refuted no longer excludes a client from CDF-AD and CDF-CH.** The problem list's Verification (core's condition-verification list) is now read, so only a diagnosis that stands removes the client from the denominator.
- **Fixed: a questionnaire whose form was deleted from its visit no longer counts as a depression screen.** Deleting a questionnaire form from an encounter leaves its saved response behind (`forms.deleted = 1`); those responses are now left out, as the UDS and Title X modules already did.
- **A questionnaire response saved without its own copy of the Questionnaire is read through the registered one**, so its PHQ-9 or PHQ-2 total is found by code. OpenEMR's own form always saves the copy; an API client may not.
- **LOINC's notice:** the README now carries the notice LOINC's licence asks for, since the module reads PHQ-9 and PHQ-2 totals by their LOINC codes.
- **Checked:** end to end on OpenEMR 8.4.1, with a client whose bipolar problem is entered-in-error (counted), one whose PHQ-9 form was deleted (not screened) and one whose response has no Questionnaire copy (counted); on 0.1.3's code exactly those three fail.

## 0.1.3 (2026-10-26)

- **A staff guide:** [GUIDE.md](GUIDE.md) walks through each task by role, with the labels as the pages show them. **Help** on every page opens it at this version's tag, in a new window.
- **The guide's labels are checked:** each of the 38 labels GUIDE.md quotes in bold is a string the pages show, so a renamed button fails the tests.

## 0.1.2 (2026-10-26)

- **Text and buttons are easier to read on this module's pages.** OpenEMR's blue (#007bff) is 3.97:1 on white, under the 4.5:1 that WCAG 2.1 AA asks of normal text and that HHS's Section 504 rule requires from 2027-05-11. Under OpenEMR's light and manila themes, the module's links and primary buttons now use #0056b3 (7.0:1), and muted and warning text darker shades that stay readable on tinted rows, and a link inside text is underlined; the dark, solar and colour themes keep their own colours, and OpenEMR's own pages are unchanged.

## 0.1.1 (2026-10-25)

- **Fixed: on OpenEMR 7.0.4 and 8.0.0 to 8.0.0.3, enabling this module stopped every OpenEMR page, the login page included (HTTP 500).** Those releases have `OEGlobalsBag` without `getProjectDir()` and the other getters, which came with 8.1.0, and the module called them wherever the class existed. It now reads the values OpenEMR stores there, and openhealthcare-common 1.3.3 does the same for the web root, the kernel and the session (8.0.x's session factory has only `getWrapper()`). Checked on fresh installs of the official 7.0.2, 7.0.4 and 8.0.0 images with every module enabled, and on 8.4.1 and master.
**A questionnaire someone hasn't finished no longer counts as a depression screen.** From OpenEMR's questionnaire runtime 0.4.0 (in master, and in 8.5.0 by a backport), a submitted questionnaire is saved as completed and a draft stays in-progress; before it, every one was saved in-progress, finished or not. The PHQ-9 and PHQ-2 totals read from saved questionnaires now count a response saved before the site's first completed one whatever its status, and one saved after it only when completed or amended (openhealthcare-common 1.3.0's rule). An entered-in-error response never counts. OpenEMR's own PHQ-9 form is read as before. The rule applies only where the site's runtime is 0.4.0 or later (openhealthcare-common 1.3.3): on an older one, which saves every response in-progress, every response but entered-in-error counts, as before, even when an API client has written a completed one.

- Requires openhealthcare-common 1.3.0.

## 0.1.0 (2026-10-25)

**The CCBHC clinic-collected measures the stewards allow, in the reporting template's shape.**

- Time to Services (I-SERV), all three submeasures, with the errata's business days; New Clients by the 6-month look-back; First Contacts in the first 11 months; 31 days for an evaluation or service after the year, 24 hours for a crisis service after it.
- Screening for Social Drivers of Health (SDOH), from OpenEMR's SDOH assessment of all five domains or a billed M1207.
- Screening for Depression and Follow-Up Plan (CDF-AD, CDF-CH), from PHQ-9 and PHQ-2 totals (OpenEMR's PHQ-9 form, or saved questionnaires read through their codes), same-day referrals and care plan interventions, and the G8431, G8510 and G8433 reporting codes; bipolar disorder excludes.
- Case Load Characteristics.
- Strata by age, payer (Medicaid without dual eligibles; Title 19 CHIP with it), ethnicity and race; a client list per measure, with each client's strata and why they counted or didn't.
- Requests for services and crisis episodes, logged by staff; a Staff Worklist tab for requests with no evaluation yet.
- DEP-REM-6 (MN Community Measurement) and ASC (NCQA) are named and not computed until their stewards allow it.
- **Checked:** 9 unit tests, the rules on synthetic records; end to end on OpenEMR 8.4.1 and master, 28 checks each (`dev/ccbhc_report_check.py` in the openhealthcare workspace, in its nightly matrix).
