<?php session_start(); ?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CCS | Dashboard</title>
  <link rel="stylesheet" href="style.css"/>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700&family=Poppins:wght@600;700;800&display=swap" rel="stylesheet"/>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet"/>
</head>
<body>

  <!-- NAVBAR -->
  <nav class="navbar">
    <div class="navbar-brand">
      <img src="images/ccslogo.png" alt="Logo" class="nav-logo">
      <span>Sit-in Monitoring System <br/><span style="font-size:.75rem;font-weight:600;opacity:.55">University of Cebu — College of Computer Studies</span></span>
    </div>

    <ul class="navbar-center" id="navCenter">
      <li><a href="index.php" class="nav-link active">Home</a></li>
     <li class="dropdown">
          <a href="#" class="nav-link dropdown-toggle" id="communityToggle">
            Community <i class="fa-solid fa-caret-down" style="font-size:.72rem"></i>
          </a>
          <ul class="dropdown-menu" id="communityMenu">
            <li><a href="#">Announcements</a></li>
            <li><a href="#">Forum</a></li>
            <li><a href="#">Resources</a></li>
          </ul>
        </li>
      <li><a href="#about" class="nav-link">About</a></li>

    <button class="hamburger" id="hamburger" aria-label="Toggle menu" aria-expanded="false">
      <span></span><span></span><span></span>
    </button>


    