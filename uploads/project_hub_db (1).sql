-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3307
-- Generation Time: Mar 12, 2026 at 03:03 PM
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
  `full_name` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin`
--

INSERT INTO `admin` (`id`, `moodle_id`, `password`, `full_name`) VALUES
(1, 'ADM', '1234', 'System Admin');

-- --------------------------------------------------------

--
-- Table structure for table `form_settings`
--

CREATE TABLE `form_settings` (
  `id` int(11) NOT NULL,
  `academic_year` enum('SE','TE','BE') NOT NULL,
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
(1, 'SE', '[{\"type\":\"team-members\",\"label\":\"Team Members (Moodle ID)\",\"placeholder\":\"Enter Moodle ID\",\"options\":\"\",\"required\":true},{\"type\":\"text\",\"label\":\"Topic Preference 1\",\"placeholder\":\"Enter topic here\",\"options\":\"\",\"required\":true},{\"type\":\"text\",\"label\":\"Topic Preference 2\",\"placeholder\":\"Enter topic here\",\"options\":\"\",\"required\":true},{\"type\":\"text\",\"label\":\"Topic Preference 3\",\"placeholder\":\"Enter topic here\",\"options\":\"\",\"required\":true}]', 1, 1, 4, NULL),
(2, 'TE', '[{\"type\":\"team-members\",\"label\":\"Team Members (Moodle ID)\",\"placeholder\":\"Enter Moodle ID\",\"options\":\"\",\"required\":true},{\"type\":\"text\",\"label\":\"Topic Preference 1\",\"placeholder\":\"Enter topic here\",\"options\":\"\",\"required\":true},{\"type\":\"text\",\"label\":\"Topic Preference 2\",\"placeholder\":\"Enter topic here\",\"options\":\"\",\"required\":false},{\"type\":\"text\",\"label\":\"Topic Preference 3\",\"placeholder\":\"Enter topic here\",\"options\":\"\",\"required\":false},{\"type\":\"text\",\"label\":\"for te\",\"placeholder\":\"\",\"options\":\"\",\"required\":true}]', 1, 1, 4, NULL),
(3, 'BE', NULL, 1, 1, 4, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `guide`
--

CREATE TABLE `guide` (
  `id` int(11) NOT NULL,
  `moodle_id` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `contact_number` varchar(15) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `guide`
--

INSERT INTO `guide` (`id`, `moodle_id`, `password`, `full_name`, `contact_number`) VALUES
(1, '200201', '12345', 'Prof. Ramesh Sharma', '9800020001'),
(2, '200202', '12345', 'Prof. Anita Verma', '9800020002'),
(3, '200203', '12345', 'Prof. Sunil Patil', '9800020003'),
(4, '200204', '12345', 'Prof. Meera Desai', '9800020004');

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
  `contact_number` varchar(15) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `head`
--

INSERT INTO `head` (`id`, `moodle_id`, `password`, `full_name`, `assigned_year`, `contact_number`) VALUES
(13, '200202', '12345', 'Prof. Anita Verma', 'SE', NULL),
(14, '200204', '12345', 'Prof. Meera Desai', 'TE', NULL);

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

--
-- Dumping data for table `password_reset_requests`
--

INSERT INTO `password_reset_requests` (`id`, `role`, `moodle_id`, `status`, `created_at`) VALUES
(1, 'student', '2023001', 'pending', '2026-03-11 17:55:18');

-- --------------------------------------------------------

--
-- Table structure for table `projects`
--

CREATE TABLE `projects` (
  `id` int(11) NOT NULL,
  `leader_id` int(11) DEFAULT NULL,
  `project_year` enum('SE','TE','BE') NOT NULL,
  `department` varchar(50) DEFAULT NULL,
  `division` varchar(10) DEFAULT NULL,
  `group_name` varchar(100) DEFAULT NULL,
  `member_details` text DEFAULT NULL,
  `topic_1` varchar(255) DEFAULT NULL,
  `topic_2` varchar(255) DEFAULT NULL,
  `topic_3` varchar(255) DEFAULT NULL,
  `extra_data` text DEFAULT NULL,
  `assigned_guide_id` int(11) DEFAULT NULL,
  `final_topic` varchar(255) DEFAULT NULL,
  `is_locked` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `projects`
--

INSERT INTO `projects` (`id`, `leader_id`, `project_year`, `department`, `division`, `group_name`, `member_details`, `topic_1`, `topic_2`, `topic_3`, `extra_data`, `assigned_guide_id`, `final_topic`, `is_locked`) VALUES
(6, 1, 'SE', '', 'B', 'Group 1', 'Rohit Sharma (Leader - 2023001)\nPriya Patel (2023002)\n', 'bn', 'bn', 'hjgg', NULL, 2, 'bn', 1),
(7, 11, 'SE', '', 'A', 'Group 2', 'Aditya Singh (Leader - 240003)\r\nAarav Sharma (240001)\r\n', 'ertyuioplnt', 'zsertyhjn ', 'setyuhb', '{\"Untitled Questionpp\":\"se56tyuhjn \"}', NULL, NULL, 0);

-- --------------------------------------------------------

--
-- Table structure for table `student`
--

CREATE TABLE `student` (
  `id` int(11) NOT NULL,
  `moodle_id` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `academic_year` enum('SE','TE','BE') NOT NULL,
  `division` enum('A','B','C') NOT NULL,
  `phone_number` varchar(15) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student`
--

INSERT INTO `student` (`id`, `moodle_id`, `password`, `full_name`, `academic_year`, `division`, `phone_number`) VALUES
(1, '2023001', '12345', 'Rohit Sharma', 'SE', 'B', '0000000000'),
(2, '2023002', '12345', 'Priya Patel', 'SE', 'B', '9876543211'),
(3, '2022001', '12345', 'Amit Singh', 'TE', 'A', '9876543212'),
(4, '2021005', '12345', 'Neha Gupta', 'BE', 'C', '9876543213'),
(8, '24106049', '1234', 'suraj', 'SE', 'A', '9136144319'),
(9, '24106087', '12345', 'Raj pal', 'SE', 'A', '9800000001'),
(10, '240002', '12345', 'Vivaan Patel', 'SE', 'A', '9800000002'),
(11, '240003', '12345', 'Aditya Singh', 'SE', 'A', '9800000003'),
(12, '240004', '12345', 'Vihaan Gupta', 'SE', 'B', '9800000004'),
(13, '240005', '12345', 'Arjun Kumar', 'SE', 'B', '9800000005'),
(14, '240006', '12345', 'Sai Desai', 'SE', 'B', '9800000006'),
(15, '240007', '12345', 'Ayaan Joshi', 'SE', 'C', '9800000007'),
(16, '240008', '12345', 'Krishna Reddy', 'SE', 'C', '9800000008'),
(17, '240009', '12345', 'Ishaan Rao', 'SE', 'C', '9800000009'),
(18, '240010', '12345', 'Shaurya Nair', 'SE', 'A', '9800000010'),
(19, '240011', '12345', 'Diya Menon', 'SE', 'B', '9800000011'),
(20, '240012', '12345', 'Sanya Verma', 'SE', 'C', '9800000012'),
(21, '240013', '12345', 'Kiara Das', 'SE', 'A', '9800000013'),
(22, '240014', '12345', 'Kavya Pillai', 'SE', 'B', '9800000014'),
(23, '240015', '12345', 'Riya Bose', 'SE', 'C', '9800000015'),
(24, '240016', '12345', 'Sneha Iyer', 'SE', 'A', '9800000016'),
(25, '240017', '12345', 'Pooja Bhat', 'SE', 'B', '9800000017'),
(26, '230001', '12345', 'Rahul Mishra', 'TE', 'A', '9900000001'),
(27, '230002', '12345', 'Karan Sinha', 'TE', 'A', '9900000002'),
(28, '230003', '12345', 'Vikram Ahuja', 'TE', 'A', '9900000003'),
(29, '230004', '12345', 'Rohan Mehra', 'TE', 'B', '9900000004'),
(30, '230005', '12345', 'Sameer Jain', 'TE', 'B', '9900000005'),
(31, '230006', '12345', 'Varun Kapoor', 'TE', 'B', '9900000006'),
(32, '230007', '12345', 'Nikhil Agarwal', 'TE', 'C', '9900000007'),
(33, '230008', '12345', 'Manish Pandey', 'TE', 'C', '9900000008'),
(34, '230009', '12345', 'Deepak Tiwari', 'TE', 'C', '9900000009'),
(35, '230010', '12345', 'Anjali Yadav', 'TE', 'A', '9900000010'),
(36, '230011', '12345', 'Neha Chauhan', 'TE', 'B', '9900000011'),
(37, '230012', '12345', 'Shruti Thakur', 'TE', 'C', '9900000012'),
(38, '230013', '12345', 'Priya Dubey', 'TE', 'A', '9900000013'),
(39, '230014', '12345', 'Megha Rajput', 'TE', 'B', '9900000014'),
(40, '230015', '12345', 'Swati Soni', 'TE', 'C', '9900000015'),
(41, '230016', '12345', 'Aarti Khatri', 'TE', 'A', '9900000016'),
(42, '230017', '12345', 'Kiran Mistry', 'TE', 'B', '9900000017'),
(43, '220001', '12345', 'Siddharth Roy', 'BE', 'A', '9700000001'),
(44, '220002', '12345', 'Harsh Vardhan', 'BE', 'A', '9700000002'),
(45, '220003', '12345', 'Prateek Bhati', 'BE', 'A', '9700000003'),
(46, '220004', '12345', 'Gaurav Chawla', 'BE', 'B', '9700000004'),
(47, '220005', '12345', 'Nitin Malhotra', 'BE', 'B', '9700000005'),
(48, '220006', '12345', 'Abhishek Dixit', 'BE', 'B', '9700000006'),
(49, '220007', '12345', 'Pankaj Kulkarni', 'BE', 'C', '9700000007'),
(50, '220008', '12345', 'Vishal Deshmukh', 'BE', 'C', '9700000008'),
(51, '220009', '12345', 'Amitabh Shetty', 'BE', 'C', '9700000009'),
(52, '220010', '12345', 'Roshni Patil', 'BE', 'A', '9700000010'),
(53, '220011', '12345', 'Shikha Kale', 'BE', 'B', '9700000011'),
(54, '220012', '12345', 'Preeti Pawar', 'BE', 'C', '9700000012'),
(55, '220013', '12345', 'Monika Gaikwad', 'BE', 'A', '9700000013'),
(56, '220014', '12345', 'Sonali Jadhav', 'BE', 'B', '9700000014'),
(57, '220015', '12345', 'Rekha Shinde', 'BE', 'C', '9700000015'),
(58, '220016', '12345', 'Nisha Kamble', 'BE', 'C', '9700000016');

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
-- Table structure for table `upload_requests`
--

CREATE TABLE `upload_requests` (
  `id` int(11) NOT NULL,
  `guide_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `folder_name` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `upload_requests`
--

INSERT INTO `upload_requests` (`id`, `guide_id`, `project_id`, `folder_name`, `created_at`) VALUES
(1, 2, 0, 'ppt', '2026-03-09 14:10:28'),
(2, 2, 0, 'sdfghj', '2026-03-09 19:32:55'),
(3, 2, 6, 'ppt', '2026-03-09 19:51:47'),
(4, 2, 6, 'asdfghj', '2026-03-10 16:11:55'),
(5, 2, 6, 'final_ppt', '2026-03-12 05:13:51');

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
  ADD UNIQUE KEY `academic_year` (`academic_year`);

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
  ADD KEY `leader_id` (`leader_id`),
  ADD KEY `assigned_guide_id` (`assigned_guide_id`);

--
-- Indexes for table `student`
--
ALTER TABLE `student`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `moodle_id` (`moodle_id`);

--
-- Indexes for table `student_uploads`
--
ALTER TABLE `student_uploads`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `upload_requests`
--
ALTER TABLE `upload_requests`
  ADD PRIMARY KEY (`id`);

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `guide`
--
ALTER TABLE `guide`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `head`
--
ALTER TABLE `head`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `password_reset_requests`
--
ALTER TABLE `password_reset_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `projects`
--
ALTER TABLE `projects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `student`
--
ALTER TABLE `student`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=59;

--
-- AUTO_INCREMENT for table `student_uploads`
--
ALTER TABLE `student_uploads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `upload_requests`
--
ALTER TABLE `upload_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
