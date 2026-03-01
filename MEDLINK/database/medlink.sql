-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 01, 2026 at 08:29 PM
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
-- Database: `medlink`
--

-- --------------------------------------------------------

--
-- Table structure for table `medical_certificates`
--

CREATE TABLE `medical_certificates` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `request_id` bigint(20) UNSIGNED NOT NULL,
  `certificate_code` varchar(40) NOT NULL,
  `issued_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `issued_by` bigint(20) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `medical_requests`
--

CREATE TABLE `medical_requests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `student_user_id` bigint(20) UNSIGNED NOT NULL,
  `illness` varchar(120) NOT NULL,
  `symptoms` text NOT NULL,
  `illness_date` date NOT NULL,
  `contact_number` varchar(30) DEFAULT NULL,
  `additional_notes` text DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `submitted_date` date NOT NULL,
  `approved_date` date DEFAULT NULL,
  `rejected_date` date DEFAULT NULL,
  `valid_until` date DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `reviewed_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `medical_requests`
--

INSERT INTO `medical_requests` (`id`, `student_user_id`, `illness`, `symptoms`, `illness_date`, `contact_number`, `additional_notes`, `status`, `submitted_date`, `approved_date`, `rejected_date`, `valid_until`, `rejection_reason`, `reviewed_by`, `created_at`, `updated_at`) VALUES
(1, 1, 'Fever and Flu', 'Sakit ulo', '2026-02-11', '09850248010', 'asdf', 'Approved', '2026-02-11', '2026-02-11', NULL, '2026-02-25', NULL, 2, '2026-02-11 18:19:28', '2026-02-11 20:57:42'),
(2, 3, 'Headache and Dizziness', 'asdf', '2026-02-11', '09524728899', 'none', 'Approved', '2026-02-11', '2026-02-11', NULL, '2026-02-25', NULL, 2, '2026-02-11 20:30:10', '2026-02-11 20:54:05'),
(3, 1, 'Headache and Dizziness', 'sakit ulo', '2026-02-11', '09850248010', '', 'Rejected', '2026-02-11', NULL, '2026-02-11', NULL, 'not enough requirements', 2, '2026-02-11 21:10:03', '2026-02-11 21:10:45'),
(4, 3, 'Fever and Flu', 'ASDF', '2026-03-01', '09850248010', 'ASDF', 'Pending', '2026-03-01', NULL, NULL, NULL, NULL, NULL, '2026-03-01 18:34:38', '2026-03-01 18:34:38');

-- --------------------------------------------------------

--
-- Table structure for table `medical_request_documents`
--

CREATE TABLE `medical_request_documents` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `request_id` bigint(20) UNSIGNED NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `stored_path` varchar(500) NOT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `size_bytes` bigint(20) UNSIGNED DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `medical_request_documents`
--

INSERT INTO `medical_request_documents` (`id`, `request_id`, `original_name`, `stored_path`, `mime_type`, `size_bytes`, `uploaded_at`) VALUES
(1, 3, 'Student ID (1).png', 'uploads/medical_requests/3/57c9de3825672c742d5aecc2494687c0.png', 'image/png', 2178919, '2026-02-11 21:10:03');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(140) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `title`, `message`, `is_read`, `created_at`) VALUES
(1, 1, 'Request Rejected', 'Your medical request REQ-003 was rejected. Reason: not enough requirements', 1, '2026-02-11 21:10:45');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_code` varchar(50) NOT NULL,
  `role` enum('student','clinic') NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `email` varchar(190) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `course` varchar(120) DEFAULT NULL,
  `year_level` varchar(50) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `user_code`, `role`, `full_name`, `email`, `date_of_birth`, `course`, `year_level`, `password_hash`, `created_at`, `updated_at`) VALUES
(1, '2402324', 'student', 'Chris Xander Nazareth', 'cx6595269@gmail.com', '2005-12-27', 'BSIT 2D', '2ND YEAR', '$2y$10$UT/.aodCz1m4YfaoOC6ysueszrYfcnMC28J/a3z/1sNtbJvSM/1QW', '2026-02-11 18:18:36', '2026-02-11 21:20:22'),
(2, 'admin', 'clinic', 'Administrator', NULL, NULL, NULL, NULL, '$2y$10$SFuDX73K6z6Qb8ckn.iKDehQKtKTfyFPYdqDIyVmpPy2oyFJSTcf6', '2026-02-11 18:22:20', '2026-02-11 18:22:20'),
(3, '2402350', 'student', 'Loue Gail Olano', 's2402350@usls.edu.ph', NULL, 'BSIT', '2D', '$2y$10$EKQT2ahThqy17DFT.wbXMOjVnHEXlKkhmzJfrMSauxj1sE5UHDGeK', '2026-02-11 19:09:09', '2026-02-11 19:09:09');

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_medical_requests`
-- (See below for the actual view)
--
CREATE TABLE `v_medical_requests` (
`id` bigint(20) unsigned
,`student_user_id` bigint(20) unsigned
,`illness` varchar(120)
,`symptoms` text
,`illness_date` date
,`contact_number` varchar(30)
,`additional_notes` text
,`status` enum('Pending','Approved','Rejected')
,`submitted_date` date
,`approved_date` date
,`rejected_date` date
,`valid_until` date
,`rejection_reason` text
,`reviewed_by` bigint(20) unsigned
,`created_at` timestamp
,`updated_at` timestamp
,`request_code` varchar(7)
);

-- --------------------------------------------------------

--
-- Structure for view `v_medical_requests`
--
DROP TABLE IF EXISTS `v_medical_requests`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_medical_requests`  AS SELECT `mr`.`id` AS `id`, `mr`.`student_user_id` AS `student_user_id`, `mr`.`illness` AS `illness`, `mr`.`symptoms` AS `symptoms`, `mr`.`illness_date` AS `illness_date`, `mr`.`contact_number` AS `contact_number`, `mr`.`additional_notes` AS `additional_notes`, `mr`.`status` AS `status`, `mr`.`submitted_date` AS `submitted_date`, `mr`.`approved_date` AS `approved_date`, `mr`.`rejected_date` AS `rejected_date`, `mr`.`valid_until` AS `valid_until`, `mr`.`rejection_reason` AS `rejection_reason`, `mr`.`reviewed_by` AS `reviewed_by`, `mr`.`created_at` AS `created_at`, `mr`.`updated_at` AS `updated_at`, concat('REQ-',lpad(`mr`.`id`,3,'0')) AS `request_code` FROM `medical_requests` AS `mr` ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `medical_certificates`
--
ALTER TABLE `medical_certificates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cert_request` (`request_id`),
  ADD UNIQUE KEY `uq_cert_code` (`certificate_code`),
  ADD KEY `idx_cert_issued_at` (`issued_at`),
  ADD KEY `fk_cert_issued_by` (`issued_by`);

--
-- Indexes for table `medical_requests`
--
ALTER TABLE `medical_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_requests_student` (`student_user_id`),
  ADD KEY `idx_requests_status` (`status`),
  ADD KEY `idx_requests_submitted` (`submitted_date`),
  ADD KEY `fk_requests_reviewer` (`reviewed_by`);

--
-- Indexes for table `medical_request_documents`
--
ALTER TABLE `medical_request_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_docs_request` (`request_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notifications_user` (`user_id`,`is_read`,`created_at`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_user_code` (`user_code`),
  ADD KEY `idx_users_role` (`role`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `medical_certificates`
--
ALTER TABLE `medical_certificates`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `medical_requests`
--
ALTER TABLE `medical_requests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `medical_request_documents`
--
ALTER TABLE `medical_request_documents`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `medical_certificates`
--
ALTER TABLE `medical_certificates`
  ADD CONSTRAINT `fk_cert_issued_by` FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_cert_request` FOREIGN KEY (`request_id`) REFERENCES `medical_requests` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `medical_requests`
--
ALTER TABLE `medical_requests`
  ADD CONSTRAINT `fk_requests_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_requests_student` FOREIGN KEY (`student_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `medical_request_documents`
--
ALTER TABLE `medical_request_documents`
  ADD CONSTRAINT `fk_docs_request` FOREIGN KEY (`request_id`) REFERENCES `medical_requests` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
