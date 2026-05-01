-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 27, 2026 at 09:34 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `project_hub_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin`
--

CREATE TABLE `admin` (
  `id` int(11) NOT NULL,
  `moodle_id` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin`
--

INSERT INTO `admin` (`id`, `moodle_id`, `password`, `full_name`, `deleted_at`) VALUES
(1, 'ADM', '$2y$10$kju7avRm9b0j0RCui0fqHuu/YKjmSsmWg3ZuaxaQwTlxGmMxwr.0W', 'System Admin', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `form_settings`
--

CREATE TABLE `form_settings` (
  `id` int(11) NOT NULL,
  `academic_year` enum('SE','TE','BE') NOT NULL,
  `semester` int(11) NOT NULL DEFAULT 3,
  `form_schema` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`form_schema`)),
  `is_form_open` tinyint(1) DEFAULT 1,
  `min_team_size` int(11) DEFAULT 1,
  `max_team_size` int(11) DEFAULT 4,
  `deadline_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `form_settings`
--

INSERT INTO `form_settings` (`id`, `academic_year`, `form_schema`, `is_form_open`, `min_team_size`, `max_team_size`, `deadline_date`) VALUES
(1, 'SE', '[{\"type\":\"team-members\",\"label\":\"Team Members (Moodle ID)\",\"placeholder\":\"Enter Moodle ID\",\"options\":\"\",\"required\":true},{\"type\":\"text\",\"label\":\"Topic Preference 1\",\"placeholder\":\"Enter topic here\",\"options\":\"\",\"required\":true},{\"type\":\"text\",\"label\":\"Topic Preference 2\",\"placeholder\":\"Enter topic here\",\"options\":\"\",\"required\":true},{\"type\":\"text\",\"label\":\"Topic Preference 3\",\"placeholder\":\"Enter topic here\",\"options\":\"\",\"required\":true}]', 1, 2, 4, NULL),
(2, 'TE', '[{\"type\":\"text\",\"label\":\"Topic Preference 1\",\"placeholder\":\"Enter topic here\",\"options\":\"\",\"required\":true},{\"type\":\"team-members\",\"label\":\"Team Members (Moodle ID)\",\"placeholder\":\"Enter Moodle ID\",\"options\":\"\",\"required\":true},{\"type\":\"text\",\"label\":\"Topic Preference 2\",\"placeholder\":\"Enter topic here\",\"options\":\"\",\"required\":false},{\"type\":\"text\",\"label\":\"Topic Preference 3\",\"placeholder\":\"Enter topic here\",\"options\":\"\",\"required\":false},{\"type\":\"text\",\"label\":\"for te\",\"placeholder\":\"\",\"options\":\"\",\"required\":true}]', 1, 1, 4, NULL),
(3, 'BE', '[{\"id\":\"f_team\",\"label\":\"Team Members Configuration\",\"type\":\"team-members\",\"required\":true},{\"id\":\"f_1773677907041\",\"label\":\"topic 1\",\"type\":\"radio\",\"options\":\"Option 1,Option 2\",\"required\":true},{\"id\":\"f_1773677913343\",\"label\":\"topic 2\",\"type\":\"text\",\"options\":\"Option 1\",\"required\":false},{\"id\":\"f_1773693258896\",\"label\":\"drop\",\"type\":\"select\",\"options\":\"yes,no\",\"required\":true},{\"id\":\"f_1773693288945\",\"label\":\"multi select\",\"type\":\"checkbox\",\"options\":\"Option 1,Option 2,Option 3,Option 4\",\"required\":true},{\"id\":\"f_1773693314305\",\"label\":\"dob\",\"type\":\"date\",\"options\":\"Option 1\",\"required\":true},{\"id\":\"f_1773693329477\",\"label\":\"long\",\"type\":\"textarea\",\"options\":\"Option 1\",\"required\":true}]', 1, 1, 4, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `guide`
--

CREATE TABLE `guide` (
  `id` int(11) NOT NULL,
  `moodle_id` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `contact_number` varchar(15) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Active',
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `guide`
--

INSERT INTO `guide` (`id`, `moodle_id`, `password`, `full_name`, `contact_number`, `status`, `deleted_at`) VALUES
(1, '200201', '12345', 'Prof. Ramesh Sharma', '9800020001', 'Active', NULL),
(2, '200202', '$2y$10$VHZJP0tthyLYBmfzAcbPL.e4xa2HddzLNHjfdQhyD602rtk7M2o9i', 'anita pata  o', '9800020002', 'Active', NULL),
(3, '200203', '12345', 'Prof. Sunil Patil', '9800020003', 'Active', NULL),
(4, '200204', '12345', 'Prof. Meera Desai', '9800020004', 'Active', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `head`
--

CREATE TABLE `head` (
  `id` int(11) NOT NULL,
  `moodle_id` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `assigned_year` enum('SE','TE','BE') NOT NULL,
  `contact_number` varchar(15) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Active',
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `head`
--

INSERT INTO `head` (`id`, `moodle_id`, `password`, `full_name`, `assigned_year`, `contact_number`, `status`, `deleted_at`) VALUES
(13, '200202', '$2y$10$c/ePQ78ZrYUAMo2WsM7kiu/eAXqfTyjOAw5s8EM8WfExTjmGMR4xG', 'anita pata  o', 'SE', '9800020002', 'Active', NULL),
(14, '200204', '12345', 'Prof. Meera Desai', 'TE', NULL, 'Active', NULL),
(17, '200201', '$2y$10$09/8RnyrhvAUImR1UkAnUeI0YC3ypx0/1Mnn2hckQ074/j4bcFvhu', 'Prof. Ramesh Sharma', 'BE', NULL, 'Active', NULL),
(18, '200203', '12345', 'Prof. Sunil Patil', 'TE', NULL, 'Active', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_requests`
--

CREATE TABLE `password_reset_requests` (
  `id` int(11) NOT NULL,
  `role` varchar(50) NOT NULL,
  `moodle_id` varchar(100) NOT NULL,
  `status` varchar(50) DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `projects`
--

CREATE TABLE `projects` (
  `id` int(11) NOT NULL,
  `project_year` enum('SE','TE','BE') NOT NULL,
  `semester` int(11) NOT NULL DEFAULT 3,
  `department` varchar(50) DEFAULT NULL,
  `division` varchar(10) DEFAULT NULL,
  `group_name` varchar(100) DEFAULT NULL,
  `member_details` text DEFAULT NULL,
  `topic_1` varchar(255) DEFAULT NULL,
  `topic_2` varchar(255) DEFAULT NULL,
  `topic_3` varchar(255) DEFAULT NULL,
  `extra_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`extra_data`)),
  `assigned_guide_id` int(11) DEFAULT NULL,
  `final_topic` varchar(255) DEFAULT NULL,
  `is_locked` tinyint(1) DEFAULT 0,
  `academic_session` varchar(20) DEFAULT 'Current',
  `is_archived` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `projects`
--

INSERT INTO `projects` (`id`, `project_year`, `department`, `division`, `group_name`, `member_details`, `topic_1`, `topic_2`, `topic_3`, `extra_data`, `assigned_guide_id`, `final_topic`, `is_locked`, `academic_session`, `is_archived`) VALUES
(1, 'SE', '', 'A', 'Group 1-SE', NULL, 'ad', 'de', 'ed', NULL, 4, 'ed', 1, '2025-2026', 1),
(2, 'TE', '', 'A', 'Group 1-TE', NULL, 'ASDFGHJ', 'WEDEWQ', 'WD', '{\"for te\":\"WEDW\"}', NULL, NULL, 0, 'Current', 0),
(3, 'TE', '', 'A', 'Group 2-TE', NULL, 'ekwfh', 'asd', 'dc', '{\"for te\":\"zsd\"}', NULL, NULL, 0, 'Current', 0);

-- --------------------------------------------------------

--
-- Table structure for table `project_logs`
--

CREATE TABLE `project_logs` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `created_by_role` varchar(20) NOT NULL COMMENT 'admin, head, guide, or student',
  `created_by_id` int(11) NOT NULL COMMENT 'ID of the user who created the log',
  `created_by_name` varchar(100) NOT NULL COMMENT 'Full name of the user',
  `log_title` varchar(255) NOT NULL COMMENT 'Heading/Title of the log entry',
  `log_entries` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`log_entries`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `progress_planned` text DEFAULT NULL COMMENT 'Progress planned for this log',
  `archive` text DEFAULT NULL COMMENT 'Archive notes for this log',
  `guide_review` text DEFAULT NULL COMMENT 'Guide review for this log',
  `log_date` date DEFAULT NULL COMMENT 'Date of the log entry',
  `progress_achieved` text DEFAULT NULL COMMENT 'Progress achieved for this log'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `project_members`
--

CREATE TABLE `project_members` (
  `project_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `is_leader` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `project_members`
--

INSERT INTO `project_members` (`project_id`, `student_id`, `is_leader`, `created_at`) VALUES
(1, 605, 1, '2026-03-29 13:26:14'),
(1, 641, 0, '2026-03-29 13:26:14'),
(2, 605, 1, '2026-03-29 13:29:17'),
(2, 606, 0, '2026-03-29 13:29:17'),
(2, 607, 0, '2026-03-29 13:29:17'),
(3, 582, 0, '2026-04-27 04:21:00'),
(3, 583, 0, '2026-04-27 04:21:00'),
(3, 584, 1, '2026-04-27 04:21:00'),
(3, 585, 0, '2026-04-27 04:21:00');

-- --------------------------------------------------------

--
-- Table structure for table `student`
--

CREATE TABLE `student` (
  `id` int(11) NOT NULL,
  `moodle_id` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `academic_year` enum('SE','TE','BE') DEFAULT 'SE',
  `current_semester` int(11) NOT NULL DEFAULT 3,
  `division` varchar(10) DEFAULT NULL,
  `phone_number` varchar(15) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Active',
  `transfer_session` varchar(50) DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student`
--

INSERT INTO `student` (`id`, `moodle_id`, `password`, `full_name`, `academic_year`, `division`, `phone_number`, `status`, `transfer_session`, `deleted_at`) VALUES
(582, '1', '$2y$10$j4l2Uaqf4l1wsjFV0BFk7O7rQ.s93qCwJnzPqLmKSOpaD12O7/6D.', 'DIPIKA VIJAY BHADANE', 'TE', 'A', '9000000001', 'Active', NULL, NULL),
(583, '2', '1234', 'MOKSH PRADEEP BHANDARI', 'TE', 'A', '9000000002', 'Active', NULL, NULL),
(584, '3', '1234', 'MAITREYA VARADRAJ BHATKHANDE', 'TE', 'A', '9000000003', 'Active', NULL, NULL),
(585, '4', '1234', 'SOHAM RAJARAM BHOSALE', 'TE', 'A', '9000000004', 'Active', NULL, NULL),
(586, '5', '1234', 'SHRUTI SUBHASH BHUNDE', 'TE', 'A', '9000000005', 'Active', NULL, NULL),
(587, '6', '$2y$10$bF8dFeclBjmIpXGOQ7KEd.uIM3xQm5TfZO2icZ6pWARhLU.hBALmq', 'KOMAL TUKARAM BUNDHATE', 'TE', 'A', '9000000006', 'Active', NULL, NULL),
(588, '7', '1234', 'VIKASH TRILOKINATH CHAUBEY', 'TE', 'A', '9000000007', 'Active', NULL, NULL),
(589, '8', '1234', 'ROSHAN ACHCHHELAL CHAUHAN', 'TE', 'A', '9000000008', 'Active', NULL, NULL),
(590, '9', '1234', 'ARNAV MANOJ DARANGE', 'TE', 'A', '9000000009', 'Active', NULL, NULL),
(591, '10', '1234', 'DHANASHREE PRAKASH DESALE', 'TE', 'A', '9000000010', 'Active', NULL, NULL),
(592, '11', '1234', 'PRITESH KALPESH DHULLA', 'TE', 'A', '9000000011', 'Active', NULL, NULL),
(593, '12', '1234', 'KRRISH ARUN DUBAL', 'TE', 'A', '9000000012', 'Active', NULL, NULL),
(594, '13', '1234', 'DIKSHA TUKARAM GAIKWAD', 'TE', 'A', '9000000013', 'Active', NULL, NULL),
(595, '14', '1234', 'NACHIKET SANJAY GHARTE', 'TE', 'A', '9000000014', 'Active', NULL, NULL),
(596, '15', '$2y$10$f856NqO2XGp/.TII6M.KReTD2tN/V9jDmzNCC4KUqrZ3ijG8q9i8y', 'PRINCE DHARMENDRA GUPTA', 'TE', 'A', '9000000015', 'Active', NULL, NULL),
(597, '16', '$2y$10$H/KcgWxbe2Gxh/jyjxtOcuCK8FyDJcOygJVo8kGKAnRzRS6mYyUp2', 'SURAJ SANTOSH GUPTA', 'TE', 'A', '9000000016', 'Active', NULL, NULL),
(598, '17', '1234', 'NANDAN DINESH GURAV', 'TE', 'A', '9000000017', 'Active', NULL, NULL),
(599, '18', '1234', 'VINAY ARUN HANDGE', 'TE', 'A', '9000000018', 'Active', NULL, NULL),
(600, '19', '1234', 'AKSHAY UMESH HEGDE', 'TE', 'A', '9000000019', 'Active', NULL, NULL),
(601, '20', '1234', 'ARYA MANGESH HEGISTE', 'TE', 'A', '9000000020', 'Active', NULL, NULL),
(602, '21', '1234', 'SIDDHI RAMCHANDRA JEDHE', 'TE', 'A', '9000000021', 'Active', NULL, NULL),
(603, '22', '1234', 'DEVASHISH RAJKUMAR JHA', 'TE', 'A', '9000000022', 'Active', NULL, NULL),
(604, '23', '1234', 'SWAYAM SURYAKANT KALE', 'TE', 'A', '9000000023', 'Active', NULL, NULL),
(605, '24', '$2y$10$8/dipfyUglTvNfhZrodMO.DhIhoF0J.qznGxkNVxQEavp1zvdfzBW', 'AAKANSHA RAKESH KANNOJIA', 'TE', 'A', '9000000024', 'Active', NULL, NULL),
(606, '25', '1234', 'NEHA DEEPAK KARKHANIS', 'TE', 'A', '9000000025', 'Active', NULL, NULL),
(607, '26', '1234', 'VEDANG HIMANSHU KARMALKAR', 'TE', 'A', '9000000026', 'Active', NULL, NULL),
(608, '27', '1234', 'CHARVI RATNESH KASHYAP', 'TE', 'A', '9000000027', 'Active', NULL, NULL),
(609, '28', '1234', 'YASH KAMLESH KOTHARI', 'TE', 'A', '9000000028', 'Active', NULL, NULL),
(610, '29', '1234', 'AAYUSH MANGESH KULKARNI', 'TE', 'A', '9000000029', 'Active', NULL, NULL),
(611, '30', '1234', 'OM AJIT LAD', 'TE', 'A', '9000000030', 'Active', NULL, NULL),
(612, '31', '1234', 'KARTIK RAMESH MAHINDRAKAR', 'TE', 'A', '9000000031', 'Active', NULL, NULL),
(613, '32', '1234', 'SNEHA RAJEEV MAHTO', 'TE', 'A', '9000000032', 'Active', NULL, NULL),
(614, '33', '1234', 'BHUMIKA GOURISANKAR MANDAL', 'TE', 'A', '9000000033', 'Active', NULL, NULL),
(615, '34', '1234', 'SUVADIP KUSHAL KANTI MANDAL', 'TE', 'A', '9000000034', 'Active', NULL, NULL),
(616, '35', '1234', 'TEJAS VINAY MASKE', 'TE', 'A', '9000000035', 'Active', NULL, NULL),
(617, '36', '1234', 'ATHARVA PRAVIN MAYEKAR', 'TE', 'A', '9000000036', 'Active', NULL, NULL),
(618, '37', '1234', 'VEDANT SATISH MAYEKAR', 'TE', 'A', '9000000037', 'Active', NULL, NULL),
(619, '38', '1234', 'PRATHMESH MILIND MORE', 'TE', 'A', '9000000038', 'Active', NULL, NULL),
(620, '39', '1234', 'AAKSHAT DILIP MUKKAWAR', 'TE', 'A', '9000000039', 'Active', NULL, NULL),
(621, '40', '1234', 'ROHIT RAJENDRA NAHAR', 'TE', 'A', '9000000040', 'Active', NULL, NULL),
(622, '41', '1234', 'AARYA PRABHAKAR NICHAL', 'TE', 'A', '9000000041', 'Active', NULL, NULL),
(623, '42', '1234', 'MAYURESH KASHINATH NIKUMBH', 'TE', 'A', '9000000042', 'Active', NULL, NULL),
(624, '43', '1234', 'MANTHAN DINESH PATIL', 'TE', 'A', '9000000043', 'Active', NULL, NULL),
(625, '44', '1234', 'YATHARTH MAHESH PATNE', 'TE', 'A', '9000000044', 'Active', NULL, NULL),
(626, '45', '1234', 'ABHISHEK UJJWAL PAWAR', 'TE', 'A', '9000000045', 'Active', NULL, NULL),
(627, '46', '1234', 'OMKAR RAJESH PHALKE', 'TE', 'A', '9000000046', 'Active', NULL, NULL),
(628, '47', '1234', 'SUMIRAN DEEPAK POTE', 'TE', 'A', '9000000047', 'Active', NULL, NULL),
(629, '48', '1234', 'RAJEEV ANESH SANGALE', 'TE', 'A', '9000000048', 'Active', NULL, NULL),
(630, '49', '1234', 'PRATIK ANIL SATPUTE', 'TE', 'A', '9000000049', 'Active', NULL, NULL),
(631, '50', '1234', 'ARNAV NINAD SAWANT', 'TE', 'A', '9000000050', 'Active', NULL, NULL),
(632, '51', '1234', 'SAMRUDDHI SANJAY SHEWALE', 'TE', 'A', '9000000051', 'Active', NULL, NULL),
(633, '52', '1234', 'PARTH VIJAY SHINDE', 'TE', 'A', '9000000052', 'Active', NULL, NULL),
(634, '53', '1234', 'PUSHKRAJ RAJENDRA SHIRKE', 'TE', 'A', '9000000053', 'Active', NULL, NULL),
(635, '54', '1234', 'VIVEK KUMAR SINGH', 'TE', 'A', '9000000054', 'Active', NULL, NULL),
(636, '55', '1234', 'SHRIYA NILESH SUPEKAR', 'TE', 'A', '9000000055', 'Active', NULL, NULL),
(637, '56', '1234', 'RAJ AJAY SURVE', 'TE', 'A', '9000000056', 'Active', NULL, NULL),
(638, '57', '1234', 'YASHICA VINAYAK THANEKAR', 'TE', 'A', '9000000057', 'Active', NULL, NULL),
(639, '58', '1234', 'PRIYANSHU NIRAJ UPADHYAY', 'TE', 'A', '9000000058', 'Active', NULL, NULL),
(640, '59', '1234', 'AARYA VARDHAN MADAN KUMAR VANKEEPURAM', 'TE', 'A', '9000000059', 'Active', NULL, NULL),
(641, '60', '1234', 'NUPUR DINESH VASAIKAR', 'TE', 'A', '9000000060', 'Active', NULL, NULL),
(642, '61', '1234', 'AASTHA KAILASH VYAS', 'TE', 'A', '9000000061', 'Active', NULL, NULL),
(643, '62', '1234', 'ANNANAY ANIL VYAS', 'TE', 'A', '9000000062', 'Active', NULL, NULL),
(644, '63', '1234', 'SAHIL PRASHANT WAGHE', 'TE', 'A', '9000000063', 'Active', NULL, NULL),
(645, '64', '1234', 'OJAS DEVDUTT WALKE', 'TE', 'A', '9000000064', 'Active', NULL, NULL),
(646, '65', '1234', 'VAISHNAVI SANTOSH WELANKAR', 'TE', 'A', '9000000065', 'Active', NULL, NULL),
(647, '66', '1234', 'ARYAN ARUN YADAV', 'TE', 'A', '9000000066', 'Active', NULL, NULL),
(648, '67', '1234', 'SIMRUN RAMAKANT YADAV', 'TE', 'A', '9000000067', 'Active', NULL, NULL),
(649, '68', '1234', 'SURAJ PARSHURAM YADAV', 'TE', 'A', '9000000068', 'Active', NULL, NULL),
(650, '69', '1234', 'STUTI SANTOSH YEWLE', 'TE', 'A', '9000000069', 'Active', NULL, NULL),
(651, '70', '1234', 'ISHWAR NARAYANNATH YOGI', 'TE', 'A', '9000000070', 'Active', NULL, NULL),
(652, '141', '1234', 'Sanwar Parag Mukkirwar', 'TE', 'C', '9876543210', 'Active', 'active', NULL),
(653, '142', '1234', 'Waqqas Abdul Rashid Mulla', 'TE', 'C', '9876543211', 'Active', 'active', NULL),
(654, '143', '$2y$10$Iras41ejRniZoq2wukl.s.bClVE9zzMcPvJZUdfbm2xbAC7mN.FxS', 'Jiya Dilip Nandu', 'TE', 'C', '9876543212', 'Active', 'active', NULL),
(655, '144', '1234', 'Pragati Sudam Nayak', 'TE', 'C', '9876543213', 'Active', 'active', NULL),
(656, '145', '1234', 'Sumitkumar Santosh Padhy', 'TE', 'C', '9876543214', 'Active', 'active', NULL),
(657, '146', '1234', 'Prince Ramshankar Pal', 'TE', 'C', '9876543215', 'Active', 'active', NULL),
(658, '147', '1234', 'Manashree Mandar Palkar', 'TE', 'C', '9876543216', 'Active', 'active', NULL),
(659, '148', '1234', 'Sai Rajkumar Panditkar', 'TE', 'C', '9876543217', 'Active', 'active', NULL),
(660, '149', '1234', 'Manthan Dattatray Pandrekar', 'TE', 'C', '9876543218', 'Active', 'active', NULL),
(661, '150', '1234', 'Akshay Anilkumar Parate', 'TE', 'C', '9876543219', 'Active', 'active', NULL),
(662, '151', '1234', 'Tushar Tukaram Parkhe', 'TE', 'C', '9876543220', 'Active', 'active', NULL),
(663, '152', '1234', 'Shreya Sachin Parulekar', 'TE', 'C', '9876543221', 'Active', 'active', NULL),
(664, '153', '1234', 'Harshika Pramod Patil', 'TE', 'C', '9876543222', 'Active', 'active', NULL),
(665, '154', '1234', 'Jay Deepak Patil', 'TE', 'C', '9876543223', 'Active', 'active', NULL),
(666, '155', '1234', 'Jeet Balaram Patil', 'TE', 'C', '9876543224', 'Active', 'active', NULL),
(667, '156', '1234', 'Manav Ravindra Patil', 'TE', 'C', '9876543225', 'Active', 'active', NULL),
(668, '157', '1234', 'Mansi Sandip Patil', 'TE', 'C', '9876543226', 'Active', 'active', NULL),
(669, '158', '1234', 'Prathamesh Gopal Patil', 'TE', 'C', '9876543227', 'Active', 'active', NULL),
(670, '159', '1234', 'Riya Hemant Patil', 'TE', 'C', '9876543228', 'Active', 'active', NULL),
(671, '160', '1234', 'Rohini Chandrakant Patil', 'TE', 'C', '9876543229', 'Active', 'active', NULL),
(672, '161', '1234', 'Kartik Mahesh Pem', 'TE', 'C', '9876543230', 'Active', 'active', NULL),
(673, '162', '1234', 'Vedant Atul Phase', 'TE', 'C', '9876543231', 'Active', 'active', NULL),
(674, '163', '1234', 'Harshad Pravin Potdar', 'TE', 'C', '9876543232', 'Active', 'active', NULL),
(675, '164', '1234', 'Vedant Dhanesh Powale', 'TE', 'C', '9876543233', 'Active', 'active', NULL),
(676, '165', '1234', 'Tanish Naresh Punamiya', 'TE', 'C', '9876543234', 'Active', 'active', NULL),
(677, '166', '1234', 'Smit Sujeet Rane', 'TE', 'C', '9876543235', 'Active', 'active', NULL),
(678, '167', '1234', 'Dhruv Vijay Ranim', 'TE', 'C', '9876543236', 'Active', 'active', NULL),
(679, '168', '1234', 'Tanmay Ravindra Repale', 'TE', 'C', '9876543237', 'Active', 'active', NULL),
(680, '169', '1234', 'Swayam Balaram Rout', 'TE', 'C', '9876543238', 'Active', 'active', NULL),
(681, '170', '1234', 'Pravin Brundaban Sahu', 'TE', 'C', '9876543239', 'Active', 'active', NULL),
(682, '171', '1234', 'Aditya Chandrashekhar Salvi', 'TE', 'C', '9876543240', 'Active', 'active', NULL),
(683, '172', '1234', 'Anish Atul Salvi', 'TE', 'C', '9876543241', 'Active', 'active', NULL),
(684, '173', '1234', 'Omkar Vilas Salvi', 'TE', 'C', '9876543242', 'Active', 'active', NULL),
(685, '174', '1234', 'Aditya Dinkar Sambare', 'TE', 'C', '9876543243', 'Active', 'active', NULL),
(686, '175', '1234', 'Hemant Ram Sarnobat', 'TE', 'C', '9876543244', 'Active', 'active', NULL),
(687, '176', '1234', 'Kumel Aziz Shaikh Mo.', 'TE', 'C', '9876543245', 'Active', 'active', NULL),
(688, '177', '1234', 'Mohd Tabish Sabir Shaikh', 'TE', 'C', '9876543246', 'Active', 'active', NULL),
(689, '178', '1234', 'Shlok Patil', 'TE', 'C', '9876543247', 'Active', 'active', NULL),
(690, '179', '1234', 'Aditya Shrikant Shukla', 'TE', 'C', '9876543248', 'Active', 'active', NULL),
(691, '180', '1234', 'Ayush Vijaykumar Singh', 'TE', 'C', '9876543249', 'Active', 'active', NULL),
(692, '181', '1234', 'Jagruti Chandrabhan Singh', 'TE', 'C', '9876543250', 'Active', 'active', NULL),
(693, '182', '1234', 'Kshitiu Ranjit Singh', 'TE', 'C', '9876543251', 'Active', 'active', NULL),
(694, '183', '1234', 'Kuwar Kaptan Singh', 'TE', 'C', '9876543252', 'Active', 'active', NULL),
(695, '184', '1234', 'Riya Harishankar Singh', 'TE', 'C', '9876543253', 'Active', 'active', NULL),
(696, '185', '1234', 'Manthan Hemraj Sonawane', 'TE', 'C', '9876543254', 'Active', 'active', NULL),
(697, '186', '1234', 'Keshav Kumar Suresh Kumar Soni', 'TE', 'C', '9876543255', 'Active', 'active', NULL),
(698, '187', '1234', 'Harsh Lachhiram Suthar', 'TE', 'C', '9876543256', 'Active', 'active', NULL),
(699, '188', '1234', 'Harshita Jaideep Waghmare', 'TE', 'C', '9876543257', 'Active', 'active', NULL),
(700, '189', '1234', 'Susheel Umashankar Yadav', 'TE', 'C', '9876543258', 'Active', 'active', NULL),
(701, '190', '1234', 'Sai Parmesh Vithoba Yemula', 'TE', 'C', '9876543259', 'Active', 'active', NULL),
(702, '191', '1234', 'Abhinav Premkumar Singh', 'TE', 'C', '9876543260', 'Active', 'active', NULL),
(703, '192', '1234', 'Harsh Santosh Thakare', 'TE', 'C', '9876543261', 'Active', 'active', NULL),
(704, '193', '1234', 'Axansh Mahatma', 'TE', 'C', '9876543262', 'Active', 'active', NULL),
(705, '194', '1234', 'Ashik Shetty', 'TE', 'C', '9876543263', 'Active', 'active', NULL),
(706, '195', '1234', 'Vedant Mengade', 'TE', 'C', '9876543264', 'Active', 'active', NULL),
(707, '196', '1234', 'Heet Chauhan', 'TE', 'C', '9876543265', 'Active', 'active', NULL),
(708, '197', '1234', 'Raj Ajay Dabholkar', 'TE', 'C', '9876543266', 'Active', 'active', NULL),
(709, '198', '1234', 'Sujal Tanaji Desai', 'TE', 'C', '9876543267', 'Active', 'active', NULL),
(710, '199', '1234', 'Sayali Ganesh Deve', 'TE', 'C', '9876543268', 'Active', 'active', NULL),
(711, '200', '1234', 'Rushabh Mahendra Gosavi', 'TE', 'C', '9876543269', 'Active', 'active', NULL),
(712, '201', '1234', 'Ayush Shailesh Gudka', 'TE', 'C', '9876543270', 'Active', 'active', NULL),
(713, '202', '1234', 'Om Jadhav', 'TE', 'C', '9876543271', 'Active', 'active', NULL),
(714, '203', '1234', 'Malhar Dilip Jagtap', 'TE', 'C', '9876543272', 'Active', 'active', NULL),
(715, '204', '1234', 'Lara Ganesh Joshi', 'TE', 'C', '9876543273', 'Active', 'active', NULL),
(716, '205', '1234', 'Harsha Sunil Koli', 'TE', 'C', '9876543274', 'Active', 'active', NULL),
(717, '206', '1234', 'Kalash Devendra Kotecha', 'TE', 'C', '9876543275', 'Active', 'active', NULL),
(718, '207', '1234', 'Madhuri Vivekanand Loni', 'TE', 'C', '9876543276', 'Active', 'active', NULL),
(719, '208', '1234', 'Sahil Lalbahadur Madhavi', 'TE', 'C', '9876543277', 'Active', 'active', NULL),
(720, '209', '1234', 'Janhavi Tanaji More', 'TE', 'C', '9876543278', 'Active', 'active', NULL),
(721, '210', '1234', 'Shreelesh Sanjiv Pawar', 'TE', 'C', '9876543279', 'Active', 'active', NULL),
(722, '211', '1234', 'Neev Pradeep Rambhiya', 'TE', 'C', '9876543280', 'Active', 'active', NULL),
(723, '212', '1234', 'Krish Jignesh Kumar Shah', 'TE', 'C', '9876543281', 'Active', 'active', NULL),
(724, '213', '1234', 'Naman Ripan Shah', 'TE', 'C', '9876543282', 'Active', 'active', NULL),
(725, '214', '1234', 'Isha Sonawane', 'TE', 'C', '9876543283', 'Active', 'active', NULL),
(726, '215', '1234', 'Pallavi Giridhar Vartak', 'TE', 'C', '9876543284', 'Active', 'active', NULL),
(727, '216', '1234', 'Ruchil Vinod Yetuskar', 'TE', 'C', '9876543285', 'Active', 'active', NULL),
(804, '03101', '1234', 'ROHAN SANJAY PATIL', 'BE', 'A', '9810000001', 'Active', NULL, NULL),
(805, '03102', '1234', 'SNEHA ANIL KADAM', 'BE', 'A', '9810000002', 'Active', NULL, NULL),
(806, '03103', '1234', 'AMIT RAJESH CHAVAN', 'BE', 'A', '9810000003', 'Active', NULL, NULL),
(807, '03104', '1234', 'POOJA SURESH SHINDE', 'BE', 'A', '9810000004', 'Active', NULL, NULL),
(808, '03105', '1234', 'PRATIK RAMESH PAWAR', 'BE', 'A', '9810000005', 'Active', NULL, NULL),
(809, '03106', '1234', 'NEHA PRAKASH JADHAV', 'BE', 'A', '9810000006', 'Active', NULL, NULL),
(810, '03107', '1234', 'YASH VIJAY JOSHI', 'BE', 'A', '9810000007', 'Active', NULL, NULL),
(811, '03108', '1234', 'PRIYA DINESH KULKARNI', 'BE', 'A', '9810000008', 'Active', NULL, NULL),
(812, '03109', '1234', 'ADITYA AJAY DESHMUKH', 'BE', 'A', '9810000009', 'Active', NULL, NULL),
(813, '03110', '1234', 'RIYA MANOJ MAHAJAN', 'BE', 'A', '9810000010', 'Active', NULL, NULL),
(814, '03111', '1234', 'OM SANTOSH NAIK', 'BE', 'A', '9810000011', 'Active', NULL, NULL),
(815, '03112', '1234', 'SHRUTI RAJENDRA MORE', 'BE', 'A', '9810000012', 'Active', NULL, NULL),
(816, '03113', '1234', 'SAHIL DEEPAK SAWANT', 'BE', 'A', '9810000013', 'Active', NULL, NULL),
(817, '03114', '1234', 'KOMAL MILIND DESAI', 'BE', 'A', '9810000014', 'Active', NULL, NULL),
(818, '03115', '1234', 'VEDANT PRAVIN WAGH', 'BE', 'A', '9810000015', 'Active', NULL, NULL),
(819, '03116', '1234', 'DIKSHA SATISH PATIL', 'BE', 'A', '9810000016', 'Active', NULL, NULL),
(820, '03117', '1234', 'ARNAV KASHINATH KADAM', 'BE', 'A', '9810000017', 'Active', NULL, NULL),
(821, '03201', '1234', 'SIDDHI UJJWAL CHAVAN', 'BE', 'B', '9820000001', 'Active', NULL, NULL),
(822, '03202', '1234', 'SOHAM RAMAKANT SHINDE', 'BE', 'B', '9820000002', 'Active', NULL, NULL),
(823, '03203', '1234', 'AAKANSHA PARSHURAM PAWAR', 'BE', 'B', '9820000003', 'Active', NULL, NULL),
(824, '03204', '1234', 'TEJAS SANJAY JADHAV', 'BE', 'B', '9820000004', 'Active', NULL, NULL),
(825, '03205', '1234', 'CHARVI ANIL JOSHI', 'BE', 'B', '9820000005', 'Active', NULL, NULL),
(826, '03206', '1234', 'MANTHAN RAJESH KULKARNI', 'BE', 'B', '9820000006', 'Active', NULL, NULL),
(827, '03207', '1234', 'BHUMIKA SURESH DESHMUKH', 'BE', 'B', '9820000007', 'Active', NULL, NULL),
(828, '03208', '1234', 'OJAS RAMESH MAHAJAN', 'BE', 'B', '9820000008', 'Active', NULL, NULL),
(829, '03209', '1234', 'SAMRUDDHI PRAKASH NAIK', 'BE', 'B', '9820000009', 'Active', NULL, NULL),
(830, '03210', '1234', 'ARYAN VIJAY MORE', 'BE', 'B', '9820000010', 'Active', NULL, NULL),
(831, '03211', '1234', 'STUTI DINESH SAWANT', 'BE', 'B', '9820000011', 'Active', NULL, NULL),
(832, '03212', '1234', 'SURAJ AJAY DESAI', 'BE', 'B', '9820000012', 'Active', NULL, NULL),
(833, '03213', '1234', 'VAISHNAVI MANOJ WAGH', 'BE', 'B', '9820000013', 'Active', NULL, NULL),
(834, '03214', '1234', 'VIVAAN SANTOSH PATIL', 'BE', 'B', '9820000014', 'Active', NULL, NULL),
(835, '03215', '1234', 'KIARA RAJENDRA KADAM', 'BE', 'B', '9820000015', 'Active', NULL, NULL),
(836, '03216', '1234', 'ISHAAN DEEPAK CHAVAN', 'BE', 'B', '9820000016', 'Active', NULL, NULL),
(837, '03217', '1234', 'KAVYA MILIND SHINDE', 'BE', 'B', '9820000017', 'Active', NULL, NULL),
(838, '03301', '1234', 'AYAAN PRAVIN PAWAR', 'BE', 'C', '9830000001', 'Active', NULL, NULL),
(839, '03302', '1234', 'SANYA SATISH JADHAV', 'BE', 'C', '9830000002', 'Active', NULL, NULL),
(840, '03303', '1234', 'KRISHNA KASHINATH JOSHI', 'BE', 'C', '9830000003', 'Active', NULL, NULL),
(841, '03304', '1234', 'DIYA UJJWAL KULKARNI', 'BE', 'C', '9830000004', 'Active', NULL, NULL),
(842, '03305', '1234', 'SHAURYA RAMAKANT DESHMUKH', 'BE', 'C', '9830000005', 'Active', NULL, NULL),
(843, '03306', '1234', 'AAROHI PARSHURAM MAHAJAN', 'BE', 'C', '9830000006', 'Active', NULL, NULL),
(844, '03307', '1234', 'VIKRAM SANJAY NAIK', 'BE', 'C', '9830000007', 'Active', NULL, NULL),
(845, '03308', '1234', 'TANVI ANIL MORE', 'BE', 'C', '9830000008', 'Active', NULL, NULL),
(846, '03309', '1234', 'RUDRA RAJESH SAWANT', 'BE', 'C', '9830000009', 'Active', NULL, NULL),
(847, '03310', '1234', 'MEERA SURESH DESAI', 'BE', 'C', '9830000010', 'Active', NULL, NULL),
(848, '03311', '1234', 'KUNAL RAMESH WAGH', 'BE', 'C', '9830000011', 'Active', NULL, NULL),
(849, '03312', '1234', 'ANANYA PRAKASH PATIL', 'BE', 'C', '9830000012', 'Active', NULL, NULL),
(850, '03313', '1234', 'HARSH VIJAY KADAM', 'BE', 'C', '9830000013', 'Active', NULL, NULL),
(851, '03314', '1234', 'NIDHI DINESH CHAVAN', 'BE', 'C', '9830000014', 'Active', NULL, NULL),
(852, '03315', '1234', 'KARTIK AJAY SHINDE', 'BE', 'C', '9830000015', 'Active', NULL, NULL),
(853, '03316', '1234', 'ISHA MANOJ PAWAR', 'BE', 'C', '9830000016', 'Active', NULL, NULL),
(854, '03317', '1234', 'DEV SANTOSH JADHAV', 'BE', 'C', '9830000017', 'Active', NULL, NULL),
(855, '04101', '1234', 'CHIRAG RAJENDRA JOSHI', 'BE', 'A', '9840000001', 'Disabled', NULL, NULL),
(856, '04102', '1234', 'ANJALI DEEPAK KULKARNI', 'BE', 'A', '9840000002', 'Disabled', NULL, NULL),
(857, '04103', '1234', 'PRANAV MILIND DESHMUKH', 'BE', 'A', '9840000003', 'Disabled', NULL, NULL),
(858, '04104', '1234', 'SONALI PRAVIN MAHAJAN', 'BE', 'A', '9840000004', 'Disabled', NULL, NULL),
(859, '04105', '1234', 'AKASH SATISH NAIK', 'BE', 'A', '9840000005', 'Disabled', NULL, NULL),
(860, '04106', '1234', 'PALLAVI KASHINATH MORE', 'BE', 'A', '9840000006', 'Disabled', NULL, NULL),
(861, '04107', '1234', 'GAURAV UJJWAL SAWANT', 'BE', 'A', '9840000007', 'Disabled', NULL, NULL),
(862, '04108', '1234', 'RUTUJA RAMAKANT DESAI', 'BE', 'A', '9840000008', 'Disabled', NULL, NULL),
(863, '04109', '1234', 'MAYUR PARSHURAM WAGH', 'BE', 'A', '9840000009', 'Disabled', NULL, NULL),
(864, '04110', '1234', 'MADHURI SANJAY PATIL', 'BE', 'A', '9840000010', 'Disabled', NULL, NULL),
(865, '04111', '1234', 'SUMIT ANIL KADAM', 'BE', 'A', '9840000011', 'Disabled', NULL, NULL),
(866, '04112', '1234', 'SWATI RAJESH CHAVAN', 'BE', 'A', '9840000012', 'Disabled', NULL, NULL),
(867, '04113', '1234', 'NIHAL SURESH SHINDE', 'BE', 'A', '9840000013', 'Disabled', NULL, NULL),
(868, '04114', '1234', 'DIVYA RAMESH PAWAR', 'BE', 'A', '9840000014', 'Disabled', NULL, NULL),
(869, '04115', '1234', 'DARSHAN PRAKASH JADHAV', 'BE', 'A', '9840000015', 'Disabled', NULL, NULL),
(870, '04116', '1234', 'ANUSHKA VIJAY JOSHI', 'BE', 'A', '9840000016', 'Disabled', NULL, NULL),
(871, '04117', '1234', 'RAJ DINESH KULKARNI', 'BE', 'A', '9840000017', 'Disabled', NULL, NULL),
(872, '04201', '1234', 'RITIKA AJAY DESHMUKH', 'BE', 'B', '9850000001', 'Disabled', NULL, NULL),
(873, '04202', '1234', 'MANDAR MANOJ MAHAJAN', 'BE', 'B', '9850000002', 'Disabled', NULL, NULL),
(874, '04203', '1234', 'GARGI SANTOSH NAIK', 'BE', 'B', '9850000003', 'Disabled', NULL, NULL),
(875, '04204', '1234', 'VARUN RAJENDRA MORE', 'BE', 'B', '9850000004', 'Disabled', NULL, NULL),
(876, '04205', '1234', 'ARYA DEEPAK SAWANT', 'BE', 'B', '9850000005', 'Disabled', NULL, NULL),
(877, '04206', '1234', 'KEDAR MILIND DESAI', 'BE', 'B', '9850000006', 'Disabled', NULL, NULL),
(878, '04207', '1234', 'MANASI PRAVIN WAGH', 'BE', 'B', '9850000007', 'Disabled', NULL, NULL),
(879, '04208', '1234', 'SIDDHANT SATISH PATIL', 'BE', 'B', '9850000008', 'Disabled', NULL, NULL),
(880, '04209', '1234', 'PRANALI KASHINATH KADAM', 'BE', 'B', '9850000009', 'Disabled', NULL, NULL),
(881, '04210', '1234', 'SUSHANT UJJWAL CHAVAN', 'BE', 'B', '9850000010', 'Disabled', NULL, NULL),
(882, '04211', '1234', 'NIKITA RAMAKANT SHINDE', 'BE', 'B', '9850000011', 'Disabled', NULL, NULL),
(883, '04212', '1234', 'AMEYA PARSHURAM PAWAR', 'BE', 'B', '9850000012', 'Disabled', NULL, NULL),
(884, '04213', '1234', 'POONAM SANJAY JADHAV', 'BE', 'B', '9850000013', 'Disabled', NULL, NULL),
(885, '04214', '1234', 'ROHIT ANIL JOSHI', 'BE', 'B', '9850000014', 'Disabled', NULL, NULL),
(886, '04215', '1234', 'SHIVANI RAJESH KULKARNI', 'BE', 'B', '9850000015', 'Disabled', NULL, NULL),
(887, '04216', '1234', 'AKHIL SURESH DESHMUKH', 'BE', 'B', '9850000016', 'Disabled', NULL, NULL),
(888, '04217', '1234', 'NEELAM RAMESH MAHAJAN', 'BE', 'B', '9850000017', 'Disabled', NULL, NULL),
(889, '04301', '1234', 'JAY PRAKASH NAIK', 'BE', 'C', '9860000001', 'Disabled', NULL, NULL),
(890, '04302', '1234', 'RADHIKA VIJAY MORE', 'BE', 'C', '9860000002', 'Disabled', NULL, NULL),
(891, '04303', '1234', 'VINAYAK DINESH SAWANT', 'BE', 'C', '9860000003', 'Disabled', NULL, NULL),
(892, '04304', '1234', 'SEJAL AJAY DESAI', 'BE', 'C', '9860000004', 'Disabled', NULL, NULL),
(893, '04305', '1234', 'RAHUL MANOJ WAGH', 'BE', 'C', '9860000005', 'Disabled', NULL, NULL),
(894, '04306', '1234', 'PRAJAKTA SANTOSH PATIL', 'BE', 'C', '9860000006', 'Disabled', NULL, NULL),
(895, '04307', '1234', 'SANKET RAJENDRA KADAM', 'BE', 'C', '9860000007', 'Disabled', NULL, NULL),
(896, '04308', '1234', 'MONIKA DEEPAK CHAVAN', 'BE', 'C', '9860000008', 'Disabled', NULL, NULL),
(897, '04309', '1234', 'KIRAN MILIND SHINDE', 'BE', 'C', '9860000009', 'Disabled', NULL, NULL),
(898, '04310', '1234', 'AKSHATA PRAVIN PAWAR', 'BE', 'C', '9860000010', 'Disabled', NULL, NULL),
(899, '04311', '1234', 'VISHAL SATISH JADHAV', 'BE', 'C', '9860000011', 'Disabled', NULL, NULL),
(900, '04312', '1234', 'MAYURI KASHINATH JOSHI', 'BE', 'C', '9860000012', 'Disabled', NULL, NULL),
(901, '04313', '1234', 'CHETAN UJJWAL KULKARNI', 'BE', 'C', '9860000013', 'Disabled', NULL, NULL),
(902, '04314', '1234', 'AARTI RAMAKANT DESHMUKH', 'BE', 'C', '9860000014', 'Disabled', NULL, NULL),
(903, '04315', '1234', 'PRATAP PARSHURAM MAHAJAN', 'BE', 'C', '9860000015', 'Disabled', NULL, NULL),
(904, '04316', '1234', 'TEJASWINI SANJAY NAIK', 'BE', 'C', '9860000016', 'Disabled', NULL, NULL),
(905, '04317', '1234', 'MAHESH ANIL MORE', 'BE', 'C', '9860000017', 'Disabled', NULL, NULL),
(906, '71', '1234', 'VEDANT VIKAS ADHIKARI', 'SE', 'B', '9812340071', 'Active', NULL, NULL),
(907, '72', '1234', 'MOHAMMED ADNAN ATIQUR REHMAN ANSARI', 'SE', 'B', '9812340072', 'Active', NULL, NULL),
(908, '73', '1234', 'MOHAMMAD TAIFOOR IRSHAD AHMAD ANSARI', 'SE', 'B', '9812340073', 'Active', NULL, NULL),
(909, '74', '1234', 'ABHISHEK BHASKAR AVHANE', 'SE', 'B', '9812340074', 'Active', NULL, NULL),
(910, '75', '1234', 'KARAN NARAYAN BABARE', 'SE', 'B', '9812340075', 'Active', NULL, NULL),
(911, '76', '1234', 'RIYA TAPAN BACHHAR', 'SE', 'B', '9812340076', 'Active', NULL, NULL),
(912, '77', '1234', 'NIMISH BHAGVANTA BANDAL', 'SE', 'B', '9812340077', 'Active', NULL, NULL),
(913, '78', '1234', 'VIRAJ VIJAY BHUNDERE', 'SE', 'B', '9812340078', 'Active', NULL, NULL),
(914, '79', '1234', 'PRINCE NILESH BHURAT', 'SE', 'B', '9812340079', 'Active', NULL, NULL),
(915, '80', '1234', 'VEDANG PRASAD BODAS', 'SE', 'B', '9812340080', 'Active', NULL, NULL),
(916, '81', '1234', 'KARAN PRASHANT BORANA', 'SE', 'B', '9812340081', 'Active', NULL, NULL),
(917, '82', '1234', 'SAHIL PARAMHANS CHAUHAN', 'SE', 'B', '9812340082', 'Active', NULL, NULL),
(918, '83', '1234', 'HRISHIKESH RAMESH CHAVAN', 'SE', 'B', '9812340083', 'Active', NULL, NULL),
(919, '84', '1234', 'ARIHANT RAKESHKUMAR CHIPPER', 'SE', 'B', '9812340084', 'Active', NULL, NULL),
(920, '85', '1234', 'ANIKET CHANDAN CHOPADA', 'SE', 'B', '9812340085', 'Active', NULL, NULL),
(921, '86', '1234', 'PRATHMESH PRAVIN CHOTHE', 'SE', 'B', '9812340086', 'Active', NULL, NULL),
(922, '87', '1234', 'OMKAR DEEPAK DEOKAR', 'SE', 'B', '9812340087', 'Active', NULL, NULL),
(923, '88', '1234', 'OM NISHIKANT DESAI', 'SE', 'B', '9812340088', 'Active', NULL, NULL),
(924, '89', '1234', 'MILIND KISHOR DHANGAR', 'SE', 'B', '9812340089', 'Active', NULL, NULL),
(925, '90', '1234', 'JAHNAVI CHITTENNA ERANKI', 'SE', 'B', '9812340090', 'Active', NULL, NULL),
(926, '91', '1234', 'BILAL SHOEB FARID', 'SE', 'B', '9812340091', 'Active', NULL, NULL),
(927, '92', '1234', 'NAMRATA RAJESH FULPAGARE', 'SE', 'B', '9812340092', 'Active', NULL, NULL),
(928, '93', '1234', 'SNEHAL JANABA GADE', 'SE', 'B', '9812340093', 'Active', NULL, NULL),
(929, '94', '1234', 'SANJANA DASHRATH GAWADE', 'SE', 'B', '9812340094', 'Active', NULL, NULL),
(930, '95', '1234', 'PRAFUL PRAVIN GHODKE', 'SE', 'B', '9812340095', 'Active', NULL, NULL),
(931, '96', '1234', 'SACHIN RAJENDRA GOSWAMI', 'SE', 'B', '9812340096', 'Active', NULL, NULL),
(932, '97', '1234', 'PARAG PREMCHAND GUPTA', 'SE', 'B', '9812340097', 'Active', NULL, NULL),
(933, '98', '1234', 'PARINI SOURABH GUPTA', 'SE', 'B', '9812340098', 'Active', NULL, NULL),
(934, '99', '1234', 'SONY DIPAK GUPTA', 'SE', 'B', '9812340099', 'Active', NULL, NULL),
(935, '100', '1234', 'YASH MANOJ GUPTA', 'SE', 'B', '9812340100', 'Active', NULL, NULL),
(936, '101', '1234', 'ADITYA MILIND INGALE', 'SE', 'B', '9812340101', 'Active', NULL, NULL),
(937, '102', '1234', 'YASHWANT RAVINDRA INJAPURI', 'SE', 'B', '9812340102', 'Active', NULL, NULL),
(938, '103', '1234', 'DHRUV SUDHIR JADHAV', 'SE', 'B', '9812340103', 'Active', NULL, NULL),
(939, '104', '1234', 'ARYA SANDEEP KUMAR JAIN', 'SE', 'B', '9812340104', 'Active', NULL, NULL),
(940, '105', '1234', 'KAVISH RAKESHKUMAR JAIN', 'SE', 'B', '9812340105', 'Active', NULL, NULL),
(941, '106', '1234', 'NISHIT BHUPENDRA JAIN', 'SE', 'B', '9812340106', 'Active', NULL, NULL),
(942, '107', '1234', 'PALAK KAPIL JAIN', 'SE', 'B', '9812340107', 'Active', NULL, NULL),
(943, '108', '1234', 'PREKSHA VINOD JAIN', 'SE', 'B', '9812340108', 'Active', NULL, NULL),
(944, '109', '1234', 'VANSH HASMUKH JAIN', 'SE', 'B', '9812340109', 'Active', NULL, NULL),
(945, '110', '1234', 'KALPESH DIGAMBAR JANGALE', 'SE', 'B', '9812340110', 'Active', NULL, NULL),
(946, '111', '1234', 'YUVAL PUNDLIK JAWARE', 'SE', 'B', '9812340111', 'Active', NULL, NULL),
(947, '112', '1234', 'TEJAS BIBHU JHA', 'SE', 'B', '9812340112', 'Active', NULL, NULL),
(948, '113', '1234', 'ATHARV RAJESH KADAM', 'SE', 'B', '9812340113', 'Active', NULL, NULL),
(949, '114', '1234', 'AMUL RAMESH KANOJIA', 'SE', 'B', '9812340114', 'Active', NULL, NULL),
(950, '115', '1234', 'GAYATRI SHASHIKANT KATKAR', 'SE', 'B', '9812340115', 'Active', NULL, NULL),
(951, '116', '1234', 'SAI DEEPTI SRIRAM KATRAGADDA', 'SE', 'B', '9812340116', 'Active', NULL, NULL),
(952, '117', '1234', 'GAURANG SHIVALING KHADE', 'SE', 'B', '9812340117', 'Active', NULL, NULL),
(953, '118', '1234', 'SAMRUDHI JITENDRA KHAIRNAR', 'SE', 'B', '9812340118', 'Active', NULL, NULL),
(954, '119', '1234', 'MAYUR RAJAN KHARCHE', 'SE', 'B', '9812340119', 'Active', NULL, NULL),
(955, '120', '1234', 'VEDANT VISHAL KIRTANE', 'SE', 'B', '9812340120', 'Active', NULL, NULL),
(956, '121', '1234', 'SIDDHESH NAMDEO KITE', 'SE', 'B', '9812340121', 'Active', NULL, NULL),
(957, '122', '1234', 'VANSH CHANDRAKANT LOKHANDE', 'SE', 'B', '9812340122', 'Active', NULL, NULL),
(958, '123', '1234', 'SAMARTH DHANESH LOLAP', 'SE', 'B', '9812340123', 'Active', NULL, NULL),
(959, '124', '1234', 'NIKHILESH DHARMESH MACHCHHAR', 'SE', 'B', '9812340124', 'Active', NULL, NULL),
(960, '125', '1234', 'DURVA BHARAT MAGDUM', 'SE', 'B', '9812340125', 'Active', NULL, NULL),
(961, '126', '1234', 'PRATIK BALU MALI', 'SE', 'B', '9812340126', 'Active', NULL, NULL),
(962, '127', '1234', 'HEMANG SATISH MANJREKAR', 'SE', 'B', '9812340127', 'Active', NULL, NULL),
(963, '128', '$2y$10$dfOoyFF9uUq/uhh0t3/qQe5SOVCsP8PwZz3TrUH/KnW2kQbSUZsqq', 'NITIN LALBAHADUR MAURYA', 'SE', 'B', '9812340128', 'Active', NULL, NULL),
(964, '129', '1234', 'YASH ANKUSH MESTRY', 'SE', 'B', '9812340129', 'Active', NULL, NULL),
(965, '130', '1234', 'SATISH DEEPAK MHAMUNKAR', 'SE', 'B', '9812340130', 'Active', NULL, NULL),
(966, '131', '1234', 'SHRUSHTI UMESH MHATRE', 'SE', 'B', '9812340131', 'Active', NULL, NULL),
(967, '132', '1234', 'KANHAIYA BANGAT MISHRA', 'SE', 'B', '9812340132', 'Active', NULL, NULL),
(968, '133', '1234', 'ROHAN RANJEET MISHRA', 'SE', 'B', '9812340133', 'Active', NULL, NULL),
(969, '134', '1234', 'SHRUTI ARUNKUMAR MISHRA', 'SE', 'B', '9812340134', 'Active', NULL, NULL),
(970, '135', '1234', 'DEBARGHO DIPANKAR MITRA', 'SE', 'B', '9812340135', 'Active', NULL, NULL),
(971, '136', '$2y$10$hFaVQ/dBk/Yp62IUotA06.iXfY60BE0I8HNmxFfH4TRRQes1Kor8K', 'SANSKAR MAHESH MORE', 'SE', 'B', '9812340136', 'Active', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `student_history`
--

CREATE TABLE `student_history` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `moodle_id` varchar(50) NOT NULL,
  `academic_year` varchar(10) NOT NULL,
  `semester` int(11) NOT NULL DEFAULT 3,
  `division` varchar(10) DEFAULT NULL,
  `academic_session` varchar(50) NOT NULL,
  `archived_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student_history`
--

INSERT INTO `student_history` (`id`, `student_id`, `moodle_id`, `academic_year`, `division`, `academic_session`, `archived_at`) VALUES
(1, 582, '1', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(2, 583, '2', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(3, 584, '3', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(4, 585, '4', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(5, 586, '5', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(6, 587, '6', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(7, 588, '7', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(8, 589, '8', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(9, 590, '9', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(10, 591, '10', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(11, 592, '11', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(12, 593, '12', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(13, 594, '13', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(14, 595, '14', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(15, 596, '15', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(16, 597, '16', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(17, 598, '17', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(18, 599, '18', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(19, 600, '19', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(20, 601, '20', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(21, 602, '21', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(22, 603, '22', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(23, 604, '23', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(24, 605, '24', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(25, 606, '25', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(26, 607, '26', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(27, 608, '27', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(28, 609, '28', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(29, 610, '29', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(30, 611, '30', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(31, 612, '31', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(32, 613, '32', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(33, 614, '33', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(34, 615, '34', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(35, 616, '35', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(36, 617, '36', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(37, 618, '37', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(38, 619, '38', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(39, 620, '39', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(40, 621, '40', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(41, 622, '41', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(42, 623, '42', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(43, 624, '43', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(44, 625, '44', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(45, 626, '45', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(46, 627, '46', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(47, 628, '47', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(48, 629, '48', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(49, 630, '49', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(50, 631, '50', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(51, 632, '51', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(52, 633, '52', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(53, 634, '53', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(54, 635, '54', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(55, 636, '55', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(56, 637, '56', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(57, 638, '57', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(58, 639, '58', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(59, 640, '59', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(60, 641, '60', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(61, 642, '61', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(62, 643, '62', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(63, 644, '63', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(64, 645, '64', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(65, 646, '65', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(66, 647, '66', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(67, 648, '67', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(68, 649, '68', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(69, 650, '69', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(70, 651, '70', 'SE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(71, 652, '141', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(72, 653, '142', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(73, 654, '143', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(74, 655, '144', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(75, 656, '145', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(76, 657, '146', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(77, 658, '147', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(78, 659, '148', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(79, 660, '149', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(80, 661, '150', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(81, 662, '151', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(82, 663, '152', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(83, 664, '153', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(84, 665, '154', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(85, 666, '155', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(86, 667, '156', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(87, 668, '157', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(88, 669, '158', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(89, 670, '159', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(90, 671, '160', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(91, 672, '161', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(92, 673, '162', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(93, 674, '163', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(94, 675, '164', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(95, 676, '165', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(96, 677, '166', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(97, 678, '167', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(98, 679, '168', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(99, 680, '169', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(100, 681, '170', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(101, 682, '171', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(102, 683, '172', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(103, 684, '173', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(104, 685, '174', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(105, 686, '175', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(106, 687, '176', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(107, 688, '177', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(108, 689, '178', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(109, 690, '179', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(110, 691, '180', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(111, 692, '181', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(112, 693, '182', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(113, 694, '183', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(114, 695, '184', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(115, 696, '185', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(116, 697, '186', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(117, 698, '187', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(118, 699, '188', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(119, 700, '189', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(120, 701, '190', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(121, 702, '191', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(122, 703, '192', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(123, 704, '193', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(124, 705, '194', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(125, 706, '195', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(126, 707, '196', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(127, 708, '197', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(128, 709, '198', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(129, 710, '199', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(130, 711, '200', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(131, 712, '201', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(132, 713, '202', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(133, 714, '203', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(134, 715, '204', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(135, 716, '205', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(136, 717, '206', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(137, 718, '207', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(138, 719, '208', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(139, 720, '209', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(140, 721, '210', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(141, 722, '211', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(142, 723, '212', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(143, 724, '213', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(144, 725, '214', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(145, 726, '215', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(146, 727, '216', 'SE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(147, 804, '03101', 'TE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(148, 805, '03102', 'TE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(149, 806, '03103', 'TE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(150, 807, '03104', 'TE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(151, 808, '03105', 'TE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(152, 809, '03106', 'TE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(153, 810, '03107', 'TE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(154, 811, '03108', 'TE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(155, 812, '03109', 'TE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(156, 813, '03110', 'TE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(157, 814, '03111', 'TE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(158, 815, '03112', 'TE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(159, 816, '03113', 'TE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(160, 817, '03114', 'TE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(161, 818, '03115', 'TE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(162, 819, '03116', 'TE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(163, 820, '03117', 'TE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(164, 821, '03201', 'TE', 'B', '2025-2026', '2026-03-29 13:28:05'),
(165, 822, '03202', 'TE', 'B', '2025-2026', '2026-03-29 13:28:05'),
(166, 823, '03203', 'TE', 'B', '2025-2026', '2026-03-29 13:28:05'),
(167, 824, '03204', 'TE', 'B', '2025-2026', '2026-03-29 13:28:05'),
(168, 825, '03205', 'TE', 'B', '2025-2026', '2026-03-29 13:28:05'),
(169, 826, '03206', 'TE', 'B', '2025-2026', '2026-03-29 13:28:05'),
(170, 827, '03207', 'TE', 'B', '2025-2026', '2026-03-29 13:28:05'),
(171, 828, '03208', 'TE', 'B', '2025-2026', '2026-03-29 13:28:05'),
(172, 829, '03209', 'TE', 'B', '2025-2026', '2026-03-29 13:28:05'),
(173, 830, '03210', 'TE', 'B', '2025-2026', '2026-03-29 13:28:05'),
(174, 831, '03211', 'TE', 'B', '2025-2026', '2026-03-29 13:28:05'),
(175, 832, '03212', 'TE', 'B', '2025-2026', '2026-03-29 13:28:05'),
(176, 833, '03213', 'TE', 'B', '2025-2026', '2026-03-29 13:28:05'),
(177, 834, '03214', 'TE', 'B', '2025-2026', '2026-03-29 13:28:05'),
(178, 835, '03215', 'TE', 'B', '2025-2026', '2026-03-29 13:28:05'),
(179, 836, '03216', 'TE', 'B', '2025-2026', '2026-03-29 13:28:05'),
(180, 837, '03217', 'TE', 'B', '2025-2026', '2026-03-29 13:28:05'),
(181, 838, '03301', 'TE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(182, 839, '03302', 'TE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(183, 840, '03303', 'TE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(184, 841, '03304', 'TE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(185, 842, '03305', 'TE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(186, 843, '03306', 'TE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(187, 844, '03307', 'TE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(188, 845, '03308', 'TE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(189, 846, '03309', 'TE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(190, 847, '03310', 'TE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(191, 848, '03311', 'TE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(192, 849, '03312', 'TE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(193, 850, '03313', 'TE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(194, 851, '03314', 'TE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(195, 852, '03315', 'TE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(196, 853, '03316', 'TE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(197, 854, '03317', 'TE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(198, 855, '04101', 'BE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(199, 856, '04102', 'BE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(200, 857, '04103', 'BE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(201, 858, '04104', 'BE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(202, 859, '04105', 'BE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(203, 860, '04106', 'BE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(204, 861, '04107', 'BE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(205, 862, '04108', 'BE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(206, 863, '04109', 'BE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(207, 864, '04110', 'BE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(208, 865, '04111', 'BE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(209, 866, '04112', 'BE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(210, 867, '04113', 'BE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(211, 868, '04114', 'BE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(212, 869, '04115', 'BE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(213, 870, '04116', 'BE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(214, 871, '04117', 'BE', 'A', '2025-2026', '2026-03-29 13:28:05'),
(215, 872, '04201', 'BE', 'B', '2025-2026', '2026-03-29 13:28:05'),
(216, 873, '04202', 'BE', 'B', '2025-2026', '2026-03-29 13:28:05'),
(217, 874, '04203', 'BE', 'B', '2025-2026', '2026-03-29 13:28:05'),
(218, 875, '04204', 'BE', 'B', '2025-2026', '2026-03-29 13:28:05'),
(219, 876, '04205', 'BE', 'B', '2025-2026', '2026-03-29 13:28:05'),
(220, 877, '04206', 'BE', 'B', '2025-2026', '2026-03-29 13:28:05'),
(221, 878, '04207', 'BE', 'B', '2025-2026', '2026-03-29 13:28:05'),
(222, 879, '04208', 'BE', 'B', '2025-2026', '2026-03-29 13:28:05'),
(223, 880, '04209', 'BE', 'B', '2025-2026', '2026-03-29 13:28:05'),
(224, 881, '04210', 'BE', 'B', '2025-2026', '2026-03-29 13:28:05'),
(225, 882, '04211', 'BE', 'B', '2025-2026', '2026-03-29 13:28:05'),
(226, 883, '04212', 'BE', 'B', '2025-2026', '2026-03-29 13:28:05'),
(227, 884, '04213', 'BE', 'B', '2025-2026', '2026-03-29 13:28:05'),
(228, 885, '04214', 'BE', 'B', '2025-2026', '2026-03-29 13:28:05'),
(229, 886, '04215', 'BE', 'B', '2025-2026', '2026-03-29 13:28:05'),
(230, 887, '04216', 'BE', 'B', '2025-2026', '2026-03-29 13:28:05'),
(231, 888, '04217', 'BE', 'B', '2025-2026', '2026-03-29 13:28:05'),
(232, 889, '04301', 'BE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(233, 890, '04302', 'BE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(234, 891, '04303', 'BE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(235, 892, '04304', 'BE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(236, 893, '04305', 'BE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(237, 894, '04306', 'BE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(238, 895, '04307', 'BE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(239, 896, '04308', 'BE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(240, 897, '04309', 'BE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(241, 898, '04310', 'BE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(242, 899, '04311', 'BE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(243, 900, '04312', 'BE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(244, 901, '04313', 'BE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(245, 902, '04314', 'BE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(246, 903, '04315', 'BE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(247, 904, '04316', 'BE', 'C', '2025-2026', '2026-03-29 13:28:05'),
(248, 905, '04317', 'BE', 'C', '2025-2026', '2026-03-29 13:28:05');

-- --------------------------------------------------------

--
-- Table structure for table `student_uploads`
--

CREATE TABLE `student_uploads` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `uploaded_by_name` varchar(100) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_logs`
--

CREATE TABLE `system_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_role` enum('admin','head','guide','student') NOT NULL,
  `user_name` varchar(255) NOT NULL,
  `action` varchar(255) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `upload_requests`
--

CREATE TABLE `upload_requests` (
  `id` int(11) NOT NULL,
  `guide_id` int(11) DEFAULT NULL,
  `project_id` int(11) NOT NULL,
  `folder_name` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `instructions` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin`
--
ALTER TABLE `admin`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `moodle_id` (`moodle_id`);

--
-- Indexes for table `form_settings`
--
ALTER TABLE `form_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `year_sem` (`academic_year`, `semester`);

--
-- Indexes for table `guide`
--
ALTER TABLE `guide`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `moodle_id` (`moodle_id`);

--
-- Indexes for table `head`
--
ALTER TABLE `head`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `moodle_id` (`moodle_id`);

--
-- Indexes for table `password_reset_requests`
--
ALTER TABLE `password_reset_requests`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_group_per_sem` (`group_name`, `project_year`, `semester`),
  ADD KEY `assigned_guide_id` (`assigned_guide_id`),
  ADD KEY `idx_projects_year_archived` (`project_year`,`is_archived`),
  ADD KEY `idx_projects_guide_archived` (`assigned_guide_id`,`is_archived`),
  ADD KEY `idx_projects_locked` (`is_locked`);

--
-- Indexes for table `project_logs`
--
ALTER TABLE `project_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_project_logs_project` (`project_id`),
  ADD KEY `idx_project_logs_created_by` (`created_by_role`,`created_by_id`),
  ADD KEY `idx_project_logs_created_at` (`created_at`),
  ADD KEY `idx_project_logs_date` (`log_date`);

--
-- Indexes for table `project_members`
--
ALTER TABLE `project_members`
  ADD PRIMARY KEY (`project_id`,`student_id`),
  ADD KEY `idx_pm_student` (`student_id`),
  ADD KEY `idx_pm_project` (`project_id`);

--
-- Indexes for table `student`
--
ALTER TABLE `student`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `moodle_id` (`moodle_id`);

--
-- Indexes for table `student_history`
--
ALTER TABLE `student_history`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `student_uploads`
--
ALTER TABLE `student_uploads`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_student_uploads_project` (`project_id`),
  ADD KEY `idx_student_uploads_request` (`request_id`);

--
-- Indexes for table `system_logs`
--
ALTER TABLE `system_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `upload_requests`
--
ALTER TABLE `upload_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_upload_requests_project` (`project_id`),
  ADD KEY `idx_upload_requests_guide` (`guide_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin`
--
ALTER TABLE `admin`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `form_settings`
--
ALTER TABLE `form_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `guide`
--
ALTER TABLE `guide`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `head`
--
ALTER TABLE `head`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `password_reset_requests`
--
ALTER TABLE `password_reset_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `projects`
--
ALTER TABLE `projects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `project_logs`
--
ALTER TABLE `project_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `student`
--
ALTER TABLE `student`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=972;

--
-- AUTO_INCREMENT for table `student_history`
--
ALTER TABLE `student_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=249;

--
-- AUTO_INCREMENT for table `student_uploads`
--
ALTER TABLE `student_uploads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_logs`
--
ALTER TABLE `system_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `upload_requests`
--
ALTER TABLE `upload_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `projects`
--
ALTER TABLE `projects`
  ADD CONSTRAINT `fk_projects_guide` FOREIGN KEY (`assigned_guide_id`) REFERENCES `guide` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `project_logs`
--
ALTER TABLE `project_logs`
  ADD CONSTRAINT `fk_logs_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `project_members`
--
ALTER TABLE `project_members`
  ADD CONSTRAINT `fk_pm_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pm_student` FOREIGN KEY (`student_id`) REFERENCES `student` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `student_uploads`
--
ALTER TABLE `student_uploads`
  ADD CONSTRAINT `fk_up_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_up_req` FOREIGN KEY (`request_id`) REFERENCES `upload_requests` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `upload_requests`
--
ALTER TABLE `upload_requests`
  ADD CONSTRAINT `fk_req_guide` FOREIGN KEY (`guide_id`) REFERENCES `guide` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_req_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
