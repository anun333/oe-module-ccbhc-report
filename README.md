# CCBHC Report

> **Status: 0.1.7, a pre-release.** Tested on OpenEMR with synthetic data only.

The quality measures a Certified Community Behavioral Health Clinic (CCBHC) collects itself, computed from OpenEMR's records and laid out as SAMHSA's data reporting template lays them out, so each table copies into the workbook. The measures follow SAMHSA's Quality Measures for Behavioral Health Clinics Technical Specifications and Resource Manual (February 2024) and its March 2025 errata.

What installing it changes in your database, what it reads and writes, and what it hooks into are listed in [FOOTPRINT.md](FOOTPRINT.md), generated from the source.

Using the module day to day, task by task for each role: see [GUIDE.md](GUIDE.md).

## What it does

- **Time to Services (I-SERV):** the average business days from a New Client's First Contact to the Initial Evaluation and to the Initial Clinical Service, and the average hours from a crisis contact to face-to-face crisis care. Business days leave out weekends, federal holidays (on their observed days) and the state holidays you list.
- **Screening for Social Drivers of Health (SDOH):** adults with a qualifying visit, and of them those screened for all five domains (food, housing, transportation, utilities, interpersonal safety) in the year, through OpenEMR's SDOH assessment or a billed M1207.
- **Screening for Depression and Follow-Up Plan (CDF-AD, 18 and older; CDF-CH, 12 to 17):** the most recent PHQ-9 or PHQ-2 on the day of a qualifying visit or in the 14 days before, negative, or positive with a follow-up (a referral or a care plan intervention) that day, or the G8431 and G8510 reporting codes. Bipolar disorder before the visit excludes, unless the problem is marked entered-in-error or refuted; G8433 is an exception. A questionnaire whose form was deleted from its visit isn't a screen.
- **Case Load Characteristics:** clients seen in the year by age, sex, ethnicity, race, insurance and (where the UDS module records it) veteran status.
- Each measure is stratified as the template asks: age where it applies, payer (Medicaid, with CHIP under Medicaid and without dual eligibles, or Non-Medicaid, on the first visit of the year), ethnicity and race (from the CDC codes OpenEMR keeps on its race and ethnicity lists). A client list per measure (CSV) says, for each client, whether they counted and why.
- **Requests for Services** (menu): the front desk logs the day a person or guardian asks for services, the First Contact the measure counts from, which OpenEMR doesn't record. The Staff Worklist, when installed, gets a **Requests for services** tab: each request with no evaluation yet, high priority past 10 business days (1 for an urgent request).
- **Crisis episodes:** when a crisis contact came in and when face-to-face crisis care began. Contacts handled on the crisis line alone are logged but not measured.

## What it does not compute

- **Depression Remission at Six Months (DEP-REM-6)** belongs to MN Community Measurement, which reserves all rights to it, and **Unhealthy Alcohol Use: Screening and Brief Counseling (ASC)** to NCQA, whose notice requires a custom license to identify records or calculate results with it. Both are required. Permission has been asked for; until it comes, compute them another way.
- The optional measures: TSC, WCC-CH and CBP-AD are NCQA's; the suicide risk measures (SRA-A, SRA-C) are reported through their electronic versions, CMS161 and CMS177.

## Who can open what

- `patients/med` for the report, its client lists and the crisis log (write or addonly to log);
- `patients/demo` for the requests log (write or addonly to log), so the front desk can keep it;
- `admin/super` for the settings: the visit categories that are Initial Evaluations and Initial Clinical Services, state holidays, and how each insurance company counts for the payer strata.

Every page view, download and change is in the audit log.

## Install

From the OpenEMR directory:

```
composer require anun333/oe-module-ccbhc-report
```

The installer plugin places it in `interface/modules/custom_modules/oe-module-ccbhc-report/`, and Composer brings [openhealthcare-common](https://github.com/anun333/openhealthcare-common) with it. Without Composer, copy this directory there and run `composer install --no-dev` in it, which puts openhealthcare-common in its own `vendor/`; the module stays switched off until it finds that package one way or the other.

Then go to Modules › Manage Modules › Unregistered, and **Register**, **Install** and **Enable** it. An administrator then chooses the visit categories in the settings; Time to Services needs them.

**Manual installs:** if you copy modules in by hand rather than through Composer, every module's own `vendor/` must hold the same version of openhealthcare-common. OpenEMR loads the library once, from the first module that registers it, so an older copy in one module is what every module gets.

**After adding, upgrading or removing a module copied in by hand, restart the container (or Apache).** OpenEMR's official Docker images (8.0.0 to 8.5.0) keep in APCu which file holds each class, and each class they didn't find, across requests: until a restart, a class once not found stays not found, and a removed module's classes still point at its folder. An OpenEMR built from source without Composer's `--apcu-autoloader` has no such cache.

### An index on a core table

Installing or upgrading adds one index to a core table: `response_id` on `form_questionnaire_assessments (response_id(64))`. The deleted-form check joins on that column, which core leaves without an index; on a test copy of a mid-size clinic (20,000 patients, 200,000 visits, 15,384 PHQ-9 responses) the index took the 2025 report from 76 s to 45 s, with the same output (reading billing by patient took it on to 6.7 s). The UDS and Title X Report modules add the same index under the same name, so it's created once; if core adds an index named `response_id` itself, the module's step is skipped. Removing the module leaves the index in place; it changes no data.

## Compatibility

Written for OpenEMR 7.0 and later through [openhealthcare-common](https://github.com/anun333/openhealthcare-common)'s compatibility layer; its bootstrap never stops a page on any version. OpenEMR's SDOH assessment came in 7.0.4; before it, only a billed M1207 counts for the SDOH measure.

**PHP security.** PHP's php2shell advisory (2026-10-21; CVE-2026-109618 and CVE-2026-109619) is remote code execution without logging in, and it is being exploited. Run OpenEMR on PHP 8.2.35, 8.3.36, 8.4.27, 8.5.12 or later.

## Development

```
composer install
vendor/bin/phpunit
php tools/footprint.php --check
```

## Sources

- SAMHSA, [Quality Measures for Behavioral Health Clinics Technical Specifications and Resource Manual](https://www.samhsa.gov/sites/default/files/ccbhc-quality-measures-technical-specifications-manual.pdf) (February 2024), the [2025 errata](https://www.samhsa.gov/sites/default/files/2025-errata-qms-for-bhc-technical-specifications-resource-manual.pdf) (March 2025) and its Appendices A and B, and the data reporting template (February 2024).
- The code lists in `data/codes.json` come from that manual. CPT codes are listed as numbers only; CPT is a registered trademark of the American Medical Association, and its descriptions are not reproduced. The manual's dental (CDT) codes are left out.
- The SDOH measure is in the public domain (CMS). Time to Services is SAMHSA's own measure.

## Other modules for OpenEMR

See [openhealthcare-common](https://github.com/anun333/openhealthcare-common) for the modules that share it.

## License

GPL-3.0-or-later, as OpenEMR. See [LICENSE](LICENSE).

The module reads PHQ-9 and PHQ-2 totals by their LOINC codes (44261-6 and 55758-7), under the [LOINC licence](https://loinc.org/license/) (Regenstrief Institute):

> This material contains content from LOINC (http://loinc.org). LOINC is copyright © Regenstrief Institute, Inc. and the Logical Observation Identifiers Names and Codes (LOINC) Committee and is available at no cost under the license at http://loinc.org/license. LOINC® is a registered United States trademark of Regenstrief Institute, Inc.
