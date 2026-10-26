--
-- CCBHC Report: install. Every statement is inside an #If...#EndIf guard, so running it again changes
-- nothing (tools/footprint.php checks that). Later versions add sql/X_Y_Z-to-A_B_C_upgrade.sql with
-- the same guarded blocks; Module Manager offers an upgrade when one's from-version is at or after the
-- installed one.
--

-- The module's settings, by name: the visit categories that are initial evaluations and initial clinical services,
-- the state holidays business days leave out, and how each insurance company counts for the payer strata.
#IfNotTable ccbhc_report_setting
CREATE TABLE `ccbhc_report_setting` (
    `name` VARCHAR(64) NOT NULL,
    `value` TEXT,
    `updated_by` INT(11) NOT NULL COMMENT 'users.id who saved it',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`name`)
) ENGINE=InnoDB COMMENT='CCBHC Report: settings';
#EndIf

-- Requests for services (First Contact, I-SERV): the day a person or their guardian asked the clinic for services,
-- which OpenEMR's record doesn't keep. A referral from another provider is not a First Contact.
#IfNotTable ccbhc_request
CREATE TABLE `ccbhc_request` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `pid` BIGINT(20) NOT NULL COMMENT 'patient_data.pid',
    `contacted_on` DATE NOT NULL COMMENT 'the day of the First Contact',
    `requested_by` VARCHAR(8) NOT NULL COMMENT 'self or guardian',
    `need` VARCHAR(8) NOT NULL COMMENT 'routine or urgent',
    `screened` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 when the preliminary screening and risk assessment were done at the contact',
    `insurance_taken` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 when insurance information was collected at the contact',
    `note` VARCHAR(255) NOT NULL DEFAULT '',
    `created_by` INT(11) NOT NULL COMMENT 'users.id',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `pid` (`pid`),
    KEY `contacted_on` (`contacted_on`)
) ENGINE=InnoDB COMMENT='CCBHC Report: requests for services';
#EndIf

-- Crisis episodes (I-SERV submeasure 3): when the crisis contact came in and when a face-to-face crisis service began.
#IfNotTable ccbhc_crisis
CREATE TABLE `ccbhc_crisis` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `pid` BIGINT(20) NOT NULL COMMENT 'patient_data.pid',
    `contacted_at` DATETIME NOT NULL COMMENT 'when the person, or someone for them, contacted the crisis service',
    `served_at` DATETIME DEFAULT NULL COMMENT 'when a face-to-face crisis service began; null when none was given',
    `hotline_only` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 when the contact was handled on the crisis line alone (not measured)',
    `note` VARCHAR(255) NOT NULL DEFAULT '',
    `created_by` INT(11) NOT NULL COMMENT 'users.id',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `pid` (`pid`),
    KEY `contacted_at` (`contacted_at`)
) ENGINE=InnoDB COMMENT='CCBHC Report: crisis episodes';
#EndIf

-- An index on core's form_questionnaire_assessments.response_id, which the questionnaire queries join on;
-- shared by name with the UDS, Title X and CCBHC Report modules (see the upgrade file that adds it).
#IfNotIndex form_questionnaire_assessments response_id
ALTER TABLE `form_questionnaire_assessments` ADD INDEX `response_id` (`response_id`(64));
#EndIf
