-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Mar 17, 2026 at 03:17 PM
-- Server version: 10.4.28-MariaDB
-- PHP Version: 8.2.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `sitin-system`
--

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `id` int(11) NOT NULL,
  `id_number` varchar(20) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `course` enum('BSIT','BSCS') NOT NULL,
  `year_level` tinyint(4) NOT NULL,
  `email` varchar(100) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `profile_photo` varchar(255) NOT NULL DEFAULT '',
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`id`, `id_number`, `last_name`, `first_name`, `middle_name`, `course`, `year_level`, `email`, `address`, `profile_photo`, `password`, `created_at`) VALUES
(1, '23745250', 'Catubay', 'Karylle', 'Catubig', 'BSIT', 3, 'karyllecatubigcatubay@gmail.com', 'Cebu City', '', '$2y$10$xCGQzRNG8xbNgm6UeZyuL.BPo2HQviCybM8HDIn8ee0D8FhjWiA56', '2026-03-10 15:59:50'),
(2, '12345678', 'Smith', 'John', '', 'BSIT', 1, 'johnsmith@gmail.com', 'Cebu City', '', '$2y$10$PDtmkm44u94C.QPWzshwbepbtJfX62mq3h0FkG1YkP6uZyRFqWaay', '2026-03-10 16:14:10');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `id_number` (`id_number`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
