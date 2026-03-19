-- Project Hub: Campus Project Management System
-- Optimized for Professional GitHub Repository

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- --------------------------------------------------------
-- 1. Table structure for table `admin`
-- --------------------------------------------------------
CREATE TABLE `admin` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `moodle_id` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `moodle_id` (`moodle_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default Admin Account (Password: 1234)
INSERT INTO `admin` (`moodle_id`, `password`, `full_name`) VALUES ('ADM', '1234', 'System Admin');

-- --------------------------------------------------------
-- 2. Table structure for table `student`
-- --------------------------------------------------------
CREATE TABLE `student` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `moodle_id` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `academic_year` enum('SE','TE','BE') NOT NULL,
  `division` enum('A','B','C') NOT NULL,
  `phone_number` varchar(15) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `moodle_id` (`moodle_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- 3. Table structure for table `projects`
-- --------------------------------------------------------
CREATE TABLE `projects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `leader_id` int(11) DEFAULT NULL,
  `project_year` enum('SE','TE','BE') NOT NULL,
  `group_name` varchar(100) DEFAULT NULL,
  `member_details` text DEFAULT NULL,
  `topic_1` varchar(255) DEFAULT NULL,
  `topic_2` varchar(255) DEFAULT NULL,
  `topic_3` varchar(255) DEFAULT NULL,
  `assigned_guide_id` int(11) DEFAULT NULL,
  `is_locked` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_group_per_year` (`group_name`,`project_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- 4. Table structure for table `guide` & `head`
-- --------------------------------------------------------
CREATE TABLE `guide` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `moodle_id` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `moodle_id` (`moodle_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `head` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `moodle_id` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `assigned_year` enum('SE','TE','BE') NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `moodle_id` (`moodle_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- 5. Supporting Tables (Settings, Uploads, Requests)
-- --------------------------------------------------------
CREATE TABLE `form_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `academic_year` enum('SE','TE','BE') NOT NULL,
  `form_schema` longtext DEFAULT NULL,
  `is_form_open` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `academic_year` (`academic_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `student_uploads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `password_reset_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role` varchar(50) NOT NULL,
  `moodle_id` varchar(100) NOT NULL,
  `status` varchar(50) DEFAULT 'pending',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Indexes for Professional Deployment
--
ALTER TABLE `admin` ADD PRIMARY KEY (`id`), ADD UNIQUE KEY `moodle_id` (`moodle_id`);
ALTER TABLE `form_settings` ADD PRIMARY KEY (`id`), ADD UNIQUE KEY `academic_year` (`academic_year`);
ALTER TABLE `guide` ADD PRIMARY KEY (`id`), ADD UNIQUE KEY `moodle_id` (`moodle_id`);
ALTER TABLE `head` ADD PRIMARY KEY (`id`), ADD UNIQUE KEY `moodle_id` (`moodle_id`);
ALTER TABLE `password_reset_requests` ADD PRIMARY KEY (`id`);
ALTER TABLE `projects` ADD PRIMARY KEY (`id`), ADD UNIQUE KEY `unique_group_per_year` (`group_name`,`project_year`);
ALTER TABLE `student` ADD PRIMARY KEY (`id`), ADD UNIQUE KEY `moodle_id` (`moodle_id`);
ALTER TABLE `student_history` ADD PRIMARY KEY (`id`);
ALTER TABLE `student_uploads` ADD PRIMARY KEY (`id`);
ALTER TABLE `upload_requests` ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for Fresh Installation
--
ALTER TABLE `admin` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;
ALTER TABLE `form_settings` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;
ALTER TABLE `guide` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;
ALTER TABLE `head` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;
ALTER TABLE `projects` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;
ALTER TABLE `student` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

COMMIT;