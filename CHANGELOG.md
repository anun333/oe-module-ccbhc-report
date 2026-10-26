# Changelog

## 0.1.5 (unreleased)

- **Pages are announced in your language.** Each page now declares the signed-in user's OpenEMR language and its direction instead of English, so a screen reader reads the translated text in that language. It comes from openhealthcare-common 1.4.0, which this version requires.
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
