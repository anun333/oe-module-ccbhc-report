# CCBHC Report: staff guide

For the staff who use the module day to day, task by task. What each measure counts and why, and how to install it, are in the [README](README.md).

The module computes the measures and lays them out as SAMHSA's reporting template does; it doesn't submit anything. You copy each table into the workbook after checking it against your own records. It has been tested only on made-up patients.

## The front desk

### Log a request for services

The measures count the time to a person's first evaluation and first clinical service from the day they, or their guardian, ask for services. OpenEMR doesn't record that day, so log it here when the person first contacts the clinic.

*Where:* the main menu › **Requests for Services**, or the **Requests for services** link on the report.

1. Type the patient's **Chart number**. Register a new person first if they aren't in OpenEMR yet.
2. Set the **Date of the request**: the day they asked, today or earlier.
3. Under **Who asked**, choose **The person** or **A parent or guardian**.
4. Under **Need**, choose **Routine (evaluation within 10 business days)** or **Urgent (within 1 business day)**.
5. Tick **Preliminary screening and risk assessment done at the contact** and **Insurance information taken** if they were.
6. Add a **Note** if useful, and choose **Log the request**.

*What you see:* **Requests in the last 90 days**, with each one's **Initial evaluation** date and the **Business days** it took, or **Not yet**.

*What it doesn't do:* a referral from another provider isn't a request. Log the day the person contacts the clinic, not the day the referral arrived.

### Follow up on requests from the Staff Worklist

If the Staff Worklist module is installed, it has a **Requests for services** tab under **Front desk**: each logged request with no initial evaluation yet, with the business days since it came in. One past its time (10 business days for a routine request, 1 for an urgent one) is high priority. Book the evaluation; recording the evaluation visit clears the row.

## Crisis staff

### Log a crisis episode

*Where:* the report › **Crisis episodes**.

1. Type the **Chart number**.
2. Set when the **Contact came in**.
3. If face-to-face care has started, set **Face-to-face care began (if it has)**. It must be after the contact.
4. If the contact was handled on the phone only, tick **Handled on the crisis line alone**.
5. Add a **Note** if useful, and choose **Log the episode**.

### Record when face-to-face care began

*Where:* **Crisis episodes**, under **Episodes in the last 90 days**.

An episode logged before care began shows **Not yet**. Set the time in that row and choose **Record**.

*What it doesn't do:* a contact more than 24 hours after the person's last one starts a new episode. Contacts handled on the crisis line alone are logged but not measured.

## The person who prepares the CCBHC report

### Run the measures for a year

*Where:* the main menu › **CCBHC Report**.

1. Choose the **Measurement Year** and **Show**.
2. Read each table and copy it into the matching tab of SAMHSA's workbook. The tables follow the template's layout, stratified by age, payer, ethnicity and race where the template asks.
3. Choose **Client list (CSV)** beside a measure to download the clients it counted, to check them against your records.

*What you see:* Time to Services (I-SERV), Screening for Social Drivers of Health (SDOH), Screening for Depression and Follow-Up Plan for adults and for ages 12 to 17, and **Case Load Characteristics**. Under them, the required measures this module doesn't compute.

*What it doesn't do:*
- Depression Remission at Six Months (DEP-REM-6) and Unhealthy Alcohol Use (ASC) aren't computed, because their owners haven't yet given permission. Report them another way.
- Time to Services needs the visit categories set in the settings first; the page says so if they aren't.
- Veteran status comes only from the UDS module's field; without it that row is empty.

## The administrator

### Choose what counts as an evaluation and a clinical service

*Where:* the report › **Settings** (administrators only).

1. Under **Visit categories that are an Initial Evaluation**, tick the visit categories your clinic uses for first evaluations. A visit billed 90791 or 90792 counts as one too.
2. Under **Visit categories that are an Initial Clinical Service**, tick the categories for the first service after the evaluation: therapy, medication management, case management.
3. List your **State holidays (one date per line, as YYYY-MM-DD)**. Federal holidays are already left out.
4. Under **Insurance companies**, set **Your choice** for any company the payer strata read wrongly, for example a CHIP plan.
5. Choose **Save**.
