-- Campus Dine Database Schema
-- Optimized for GitHub Repository

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- 1. Table structure for table `categories`
CREATE TABLE `categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dumping standard categories
INSERT INTO `categories` (`name`) VALUES
('Breakfast'), ('Lunch'), ('Drinks'), ('Snacks');

-- 2. Table structure for table `menu`
CREATE TABLE `menu` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `category` enum('Breakfast','Lunch','Drinks','Snacks') NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_sold_out` tinyint(1) DEFAULT 0,
  `avail_start` time DEFAULT '00:00:00',
  `avail_end` time DEFAULT '23:59:59',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dumping initial menu items
INSERT INTO `menu` (`name`, `category`, `price`, `description`) VALUES
('Masala Dosa', 'Breakfast', 60.00, 'Crispy dosa with potato filling'),
('Idli Sambar', 'Breakfast', 40.00, 'Steamed rice cakes with lentil soup'),
('Veg Thali', 'Lunch', 120.00, 'Complete meal with rice, roti, dal, sabzi'),
('Vada Pav', 'Snacks', 25.00, 'Mumbai burger');

-- 3. Table structure for table `orders`
-- Orders table is kept empty for a fresh installation
CREATE TABLE `orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_code` varchar(10) NOT NULL,
  `moodle_id` varchar(50) DEFAULT NULL,
  `items` text DEFAULT NULL,
  `total_price` decimal(10,2) DEFAULT NULL,
  `status` enum('Preparing','Ready','Collected') DEFAULT 'Preparing',
  `order_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `pickup_time` varchar(20) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `ready_time` datetime DEFAULT NULL,
  `collected_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_code` (`order_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Table structure for table `settings`
CREATE TABLE `settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `open_time` time DEFAULT '09:00:00',
  `close_time` time DEFAULT '16:30:00',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `settings` (`id`, `open_time`, `close_time`) VALUES (1, '08:00:00', '18:30:00');

-- 5. Table structure for table `users`
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `moodle_id` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('student','admin','teacher') NOT NULL,
  `full_name` varchar(100) DEFAULT 'Student',
  `email` varchar(100) DEFAULT '',
  `phone` varchar(15) DEFAULT '',
  `profile_pic` varchar(255) DEFAULT 'default.png',
  PRIMARY KEY (`id`),
  UNIQUE KEY `moodle_id` (`moodle_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default Admin and Test User (Passwords: admin01=12345, stu=1234)
INSERT INTO `users` (`moodle_id`, `password`, `role`, `full_name`) VALUES 
('admin01', '12345', 'admin', 'System Admin'),
('stu', '1234', 'student', 'Suraj');

COMMIT;