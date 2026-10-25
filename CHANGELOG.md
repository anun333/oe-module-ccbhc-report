# Changelog

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
