-- Semester Update SQL
-- Introduces semester-wise tracking for SE (Sem 3/4), TE (Sem 5/6), BE (Sem 7/8)

-- 1. Update 'student' table
ALTER TABLE `student` ADD COLUMN `current_semester` INT DEFAULT 0 AFTER `academic_year`;

-- 2. Update 'projects' table
ALTER TABLE `projects` ADD COLUMN `semester` INT DEFAULT 0 AFTER `project_year`;

-- 3. Update 'form_settings' table
ALTER TABLE `form_settings` ADD COLUMN `semester` INT DEFAULT 0 AFTER `academic_year`;

-- 4. Update 'student_history' table
ALTER TABLE `student_history` ADD COLUMN `semester` INT DEFAULT 0 AFTER `academic_year`;

-- 5. Initialize data based on current academic year
UPDATE `student` SET `current_semester` = 3 WHERE `academic_year` = 'SE';
UPDATE `student` SET `current_semester` = 5 WHERE `academic_year` = 'TE';
UPDATE `student` SET `current_semester` = 7 WHERE `academic_year` = 'BE';

UPDATE `projects` SET `semester` = 3 WHERE `project_year` = 'SE';
UPDATE `projects` SET `semester` = 5 WHERE `project_year` = 'TE';
UPDATE `projects` SET `semester` = 7 WHERE `project_year` = 'BE';

UPDATE `form_settings` SET `semester` = 3 WHERE `academic_year` = 'SE';
UPDATE `form_settings` SET `semester` = 5 WHERE `academic_year` = 'TE';
UPDATE `form_settings` SET `semester` = 7 WHERE `academic_year` = 'BE';

UPDATE `student_history` SET `semester` = 3 WHERE `academic_year` = 'SE';
UPDATE `student_history` SET `semester` = 5 WHERE `academic_year` = 'TE';
UPDATE `student_history` SET `semester` = 7 WHERE `academic_year` = 'BE';
