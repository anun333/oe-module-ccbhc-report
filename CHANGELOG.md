# Changelog

## 0.1.1 (unreleased)

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
