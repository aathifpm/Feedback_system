<?php
session_start();
require_once 'db_connection.php';
include 'header.php';

// Fetch statements from database
$po_query = "SELECT DISTINCT po_number, statement FROM alumni_po_assessment ORDER BY po_number";
$peo_query = "SELECT DISTINCT peo_number, statement FROM alumni_peo_assessment ORDER BY peo_number";
$pso_query = "SELECT DISTINCT pso_number, statement FROM alumni_pso_assessment ORDER BY pso_number";
$general_query = "SELECT DISTINCT question_number, statement FROM alumni_general_assessment ORDER BY question_number";

$po_result = mysqli_query($conn, $po_query);
$peo_result = mysqli_query($conn, $peo_query);
$pso_result = mysqli_query($conn, $pso_query);
$general_result = mysqli_query($conn, $general_query);

// Check for query errors
if (!$po_result || !$peo_result || !$pso_result || !$general_result) {
    die("Error fetching statements: " . mysqli_error($conn));
}

$po_statements = mysqli_fetch_all($po_result, MYSQLI_ASSOC);
$peo_statements = mysqli_fetch_all($peo_result, MYSQLI_ASSOC);
$pso_statements = mysqli_fetch_all($pso_result, MYSQLI_ASSOC);
$general_statements = mysqli_fetch_all($general_result, MYSQLI_ASSOC);

// Free results
mysqli_free_result($po_result);
mysqli_free_result($peo_result);
mysqli_free_result($pso_result);
mysqli_free_result($general_result);

// Display any error or success messages
if (isset($_SESSION['error_message'])) {
    echo '<div class="alert alert-danger">' . $_SESSION['error_message'] . '</div>';
    unset($_SESSION['error_message']);
}
if (isset($_SESSION['success_message'])) {
    echo '<div class="alert alert-success">' . $_SESSION['success_message'] . '</div>';
    unset($_SESSION['success_message']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alumni Survey - Panimalar Engineering College</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2ecc71;
            --warning-color: #f1c40f;
            --danger-color: #e74c3c;
            --text-color: #2c3e50;
            --bg-color: #e0e5ec;
            --card-bg: #e0e5ec;
            --shadow: 9px 9px 16px rgb(163,177,198,0.6), 
                     -9px -9px 16px rgba(255,255,255, 0.5);
            --inner-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                           inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
            --mobile-shadow: 5px 5px 10px rgb(163,177,198,0.6), 
                     -5px -5px 10px rgba(255,255,255, 0.5);
            --mobile-inner-shadow: inset 3px 3px 5px 0 rgba(0, 0, 0, 0.1),
                           inset -3px -3px 5px 0 rgba(255, 255, 255, 0.8);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            background: var(--bg-color);
            color: var(--text-color);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            overflow-x: hidden;
        }

        .container {
            max-width: 1200px;
            width: 90%;
            margin: 2rem auto;
            padding: 2rem;
        }

        .survey-header {
            text-align: center;
            margin-bottom: 2rem;
            padding: 2rem;
            background: var(--card-bg);
            border-radius: 20px;
            box-shadow: var(--shadow);
        }

        .survey-header h1 {
            font-size: 2rem;
            color: var(--text-color);
            margin-bottom: 0.5rem;
        }

        .survey-header h2 {
            font-size: 1.5rem;
            color: var(--primary-color);
            margin-bottom: 1rem;
        }

        .survey-header h3 {
            font-size: 1.5rem;
            color: var(--primary-color);
            margin-bottom: 1rem;
        }

        .survey-form {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
        }

        .form-section {
            position: relative;
            margin-bottom: 3rem;
            padding: 2.5rem;
            background: var(--bg-color);
            border-radius: 25px;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease;
        }

        .form-section:hover {
            transform: translateY(-5px);
        }

        .form-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: linear-gradient(to bottom, var(--primary-color), var(--secondary-color));
            border-radius: 25px 0 0 25px;
        }

        .form-section h3 {
            color: var(--primary-color);
            font-size: 1.8rem;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid rgba(52, 152, 219, 0.2);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .form-section h3 i {
            font-size: 1.5rem;
            color: var(--secondary-color);
        }

        .form-group {
            margin-bottom: 2rem;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.8rem;
            color: var(--text-color);
            font-weight: 500;
            font-size: 1rem;
        }

        .form-control {
            width: 100%;
            padding: 1rem 1.5rem;
            border: none;
            border-radius: 15px;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            color: var(--text-color);
            font-size: 1rem;
            transition: all 0.3s ease;
            -webkit-appearance: none;
            appearance: none;
        }

        .form-control:focus {
            outline: none;
            box-shadow: var(--shadow);
            transform: translateY(-2px);
        }

        .radio-group, .checkbox-group {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .radio-option, .checkbox-option {
            position: relative;
            padding: 1rem;
            background: var(--bg-color);
            border-radius: 12px;
            box-shadow: var(--inner-shadow);
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            min-height: 3.5rem;
        }

        .radio-option:hover, .checkbox-option:hover {
            box-shadow: var(--shadow);
            transform: translateY(-2px);
        }

        .radio-option input[type="radio"],
        .checkbox-option input[type="checkbox"] {
            margin-right: 0.5rem;
            width: 1.2rem;
            height: 1.2rem;
        }

        .rating-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 1rem;
            margin: 2rem 0;
        }

        .rating-table th {
            padding: 1.2rem;
            background: var(--bg-color);
            color: var(--text-color);
            font-weight: 600;
            text-align: center;
            border-radius: 12px;
            box-shadow: var(--shadow);
        }

        .rating-table td {
            padding: 1.2rem;
            background: var(--bg-color);
            text-align: center;
            border-radius: 12px;
            box-shadow: var(--inner-shadow);
            transition: all 0.3s ease;
        }

        .rating-table tr:hover td {
            box-shadow: var(--shadow);
            transform: translateY(-2px);
        }

        .rating-table td:first-child {
            font-weight: 500;
            color: var(--primary-color);
        }

        .rating-table input[type="radio"] {
            transform: scale(1.2);
            cursor: pointer;
        }

        .btn-submit {
            background: linear-gradient(45deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 1.2rem 3rem;
            border: none;
            border-radius: 50px;
            font-size: 1.2rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: var(--shadow);
            display: block;
            margin: 3rem auto;
            position: relative;
            overflow: hidden;
            -webkit-appearance: none;
            appearance: none;
        }

        .btn-submit::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(
                120deg,
                transparent,
                rgba(255, 255, 255, 0.3),
                transparent
            );
            transition: 0.5s;
        }

        .btn-submit:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 25px rgba(52, 152, 219, 0.3);
        }

        .btn-submit:hover::before {
            left: 100%;
        }

        /* Add icons to section headings */
        .form-section h3.personal-details::before {
            content: '\f007';
            font-family: 'Font Awesome 5 Free';
            margin-right: 1rem;
            color: var(--primary-color);
        }

        .form-section h3.employment-details::before {
            content: '\f0b1';
            font-family: 'Font Awesome 5 Free';
            margin-right: 1rem;
            color: var(--primary-color);
        }

        .form-section h3.higher-studies::before {
            content: '\f19d';
            font-family: 'Font Awesome 5 Free';
            margin-right: 1rem;
            color: var(--primary-color);
        }

        .form-section h3.business-details::before {
            content: '\f1ad';
            font-family: 'Font Awesome 5 Free';
            margin-right: 1rem;
            color: var(--primary-color);
        }

        /* Add progress indicator */
        .progress-bar {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            transform-origin: 0%;
            z-index: 1000;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .container {
                width: 95%;
                padding: 1rem;
                margin: 1rem auto;
            }

            .survey-header {
                padding: 1.5rem;
                margin-bottom: 1.5rem;
            }

            .survey-header h1 {
                font-size: 1.6rem;
            }

            .survey-header h2, .survey-header h3 {
                font-size: 1.3rem;
            }

            .survey-form {
                padding: 1.5rem;
            }

            .form-section {
                padding: 1.5rem;
                margin-bottom: 2rem;
            }

            .form-section h3 {
                font-size: 1.4rem;
                margin-bottom: 1.5rem;
            }

            .radio-group, .checkbox-group {
                grid-template-columns: 1fr;
               
            }



            .rating-table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
                -webkit-overflow-scrolling: touch;
            }

            .rating-table th, 
            .rating-table td {
                padding: 0.8rem;
            }

            .btn-submit {
                width: 100%;
                padding: 1rem 2rem;
                font-size: 1.1rem;
                margin: 2rem auto;
            }

            .form-control {
                padding: 0.8rem 1.2rem;
                font-size: 1rem;
            }

            .form-group {
                margin-bottom: 1.5rem;
            }

            .form-group label {
                font-size: 0.95rem;
                margin-bottom: 0.6rem;
            }
        }

        @media (max-width: 480px) {
            .container {
                width: 100%;
                padding: 0.8rem;
                margin: 0.8rem auto;
            }

            .survey-header {
                padding: 1.2rem;
                margin-bottom: 1.2rem;
                border-radius: 15px;
            }

            .survey-form {
                padding: 1.2rem;
                border-radius: 15px;
            }

            .form-section {
                padding: 1.2rem;
                margin-bottom: 1.5rem;
                border-radius: 15px;
            }

            .form-section h3 {
                font-size: 1.3rem;
                margin-bottom: 1.2rem;
            }

            .radio-option, .checkbox-option {
                padding: 0.7rem;
                min-height: 2.8rem;
            }

            .rating-table th, 
            .rating-table td {
                padding: 0.7rem;
            }

            .btn-submit {
                padding: 0.9rem 1.8rem;
                font-size: 1rem;
                margin: 1.5rem auto;
            }

            .form-control {
                padding: 0.7rem 1rem;
                font-size: 0.95rem;
                border-radius: 12px;
            }

            .form-group {
                margin-bottom: 1.2rem;
            }

            .form-group label {
                font-size: 0.9rem;
                margin-bottom: 0.5rem;
            }

            .scale-info {
                font-size: 0.85rem;
                padding: 0.8rem;
                margin-bottom: 1.2rem;
            }
        }

        .alert {
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 10px;
            text-align: center;
            font-weight: 500;
            box-shadow: var(--shadow);
        }
        
        .alert-danger {
            background-color: #ffe5e5;
            color: var(--danger-color);
            border: 1px solid var(--danger-color);
        }
        
        .alert-success {
            background-color: #e5ffe5;
            color: var(--secondary-color);
            border: 1px solid var(--secondary-color);
        }

        /* Add loading overlay styles */
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.9);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }

        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Add form validation styles */
        .form-control.error {
            border: 2px solid var(--danger-color);
        }

        .error-message {
            color: var(--danger-color);
            font-size: 0.85rem;
            margin-top: 0.5rem;
            display: none;
        }

        .scale-info {
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: var(--bg-color);
            border-radius: 15px;
            box-shadow: var(--inner-shadow);
            color: var(--text-color);
            font-size: 0.9rem;
        }

        /* Update the rating-radio-group styles for horizontal alignment */
        .rating-radio-group {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.1rem;
            padding: 0.5rem 1rem;
            background: var(--bg-color);
            border-radius: 15px;
            box-shadow: var(--inner-shadow);
            width: 100%;
            max-width: 400px;
            margin: 0 auto;
        }

        .rating-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.1rem;
            width: 100%;
        }

        .rating-option {
            flex: 1;
            display: flex;
            justify-content: center;
        }

        .rating-input {
            clip: rect(0 0 0 0);
            clip-path: inset(50%);
            height: 1px;
            overflow: hidden;
            position: absolute;
            white-space: nowrap;
            width: 1px;
        }

        .rating-label {
            width: 40px;
            height: 40px;
            display: flex;
            justify-content: center;
            align-items: center;
            border-radius: 50%;
            background: var(--bg-color);
            box-shadow: var(--shadow);
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            color: var(--text-color);
            position: relative;
        }

        .rating-input:checked + .rating-label {
            background: var(--primary-color);
            color: white;
            box-shadow: inset 3px 3px 6px rgba(0, 0, 0, 0.2),
                       inset -3px -3px 6px rgba(255, 255, 255, 0.1);
            transform: scale(1.1);
        }

        .rating-label:hover {
            transform: scale(1.05);
            background: #edf2f7;
        }

        .rating-input:focus + .rating-label {
            outline: 2px solid var(--primary-color);
            outline-offset: 2px;
        }

        /* Responsive adjustments for rating buttons */
        @media (max-width: 768px) {
            .rating-radio-group {
                padding: 0.4rem 0.8rem;
                gap: 0.1rem;
                max-width: 300px;
            }

            .rating-options {
                gap: 0.1rem;
            }

            .rating-label {
                width: 35px;
                height: 35px;
                font-size: 0.9rem;
            }
        }

        @media (max-width: 480px) {
            .rating-radio-group {
                padding: 0.3rem 0.6rem;
                gap: 0.1rem;
                max-width: 260px;
            }

            .rating-options {
                gap: 0.1rem;
            }

            .rating-label {
                width: 30px;
                height: 30px;
                font-size: 0.8rem;
            }
        }

        /* Mobile navigation */
        .mobile-nav {
            display: none;
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            background: var(--card-bg);
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
            z-index: 100;
            padding: 0.8rem;
        }

        .mobile-nav-buttons {
            display: flex;
            justify-content: space-between;
            max-width: 600px;
            margin: 0 auto;
        }

        .mobile-nav-btn {
            background: var(--bg-color);
            border: none;
            border-radius: 50%;
            width: 3rem;
            height: 3rem;
            display: flex;
            justify-content: center;
            align-items: center;
            color: var(--primary-color);
            font-size: 1.2rem;
            box-shadow: var(--inner-shadow);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .mobile-nav-btn:active {
            box-shadow: var(--mobile-inner-shadow);
            transform: scale(0.95);
        }

        .mobile-nav-btn.active {
            background: var(--primary-color);
            color: white;
        }

        @media (max-width: 768px) {
            .mobile-nav {
                display: block;
            }
            
            body {
                padding-bottom: 5rem;
            }
        }

        /* Mobile-friendly select dropdowns */
        select.form-control {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%233498db' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1rem;
            padding-right: 2.5rem;
        }

        /* Mobile-friendly date input */
        input[type="date"].form-control {
            position: relative;
        }

        input[type="date"].form-control::-webkit-calendar-picker-indicator {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            width: 1.2rem;
            height: 1.2rem;
            opacity: 0.7;
        }

        /* Mobile-friendly textarea */
        textarea.form-control {
            resize: vertical;
            min-height: 5rem;
        }

        /* Mobile-friendly checkbox and radio */
        input[type="checkbox"], input[type="radio"] {
            -webkit-appearance: none;
            appearance: none;
            width: 1.2rem;
            height: 1.2rem;
            border: 2px solid var(--primary-color);
            border-radius: 3px;
            margin-right: 0.5rem;
            position: relative;
            cursor: pointer;
        }

        input[type="radio"] {
            border-radius: 50%;
        }

        input[type="checkbox"]:checked {
            background-color: var(--primary-color);
        }

        input[type="checkbox"]:checked::after {
            content: '✓';
            color: white;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 0.8rem;
        }

        input[type="radio"]:checked {
            background-color: var(--primary-color);
        }

        input[type="radio"]:checked::after {
            content: '';
            width: 0.5rem;
            height: 0.5rem;
            background-color: white;
            border-radius: 50%;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }

        /* Mobile rating cards */
        .mobile-rating-cards {
            display: none;
            flex-direction: column;
            gap: 1.5rem;
            margin: 1.5rem 0;
        }

        .rating-card {
            background: var(--bg-color);
            border-radius: 15px;
            box-shadow: var(--shadow);
            overflow: hidden;
            transition: transform 0.3s ease;
        }

        .rating-card:active {
            transform: scale(0.98);
        }

        .rating-card-header {
            padding: 1rem;
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            color: white;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .rating-card-number {
            background: rgba(255, 255, 255, 0.2);
            width: 2rem;
            height: 2rem;
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            font-weight: 600;
        }

        .rating-card-title {
            font-size: 1rem;
            font-weight: 500;
            margin: 0;
            flex: 1;
        }

        .rating-card-body {
            padding: 1.2rem;
        }

        .rating-card .rating-radio-group {
            max-width: 100%;
        }

        .rating-card .rating-options {
            justify-content: center;
            flex-wrap: nowrap;
        }

        @media (max-width: 480px) {
            .rating-card-body {
                padding: 0.8rem 0.5rem;
            }

            .rating-card .rating-radio-group {
                padding: 0.3rem;
            }

            .rating-card .rating-options {
                gap: 0.1rem;
            }

            .rating-card .rating-label {
                width: 28px;
                height: 28px;
                font-size: 0.85rem;
            }
        }

        @media (max-width: 360px) {
            .rating-card .rating-label {
                width: 25px;
                height: 25px;
                font-size: 0.8rem;
            }
        }

        /* Desktop/Mobile visibility classes */
        .desktop-only {
            display: block;
        }

        .mobile-only {
            display: none;
        }

        @media (max-width: 768px) {
            .desktop-only {
                display: none;
            }

            .mobile-only {
                display: block;
            }

            .mobile-rating-cards {
                display: flex;
            }
        }

        /* Improved mobile form controls */
        @media (max-width: 768px) {
            .form-control {
                font-size: 16px; /* Prevents iOS zoom on focus */
            }

            select.form-control {
                background-position: right 0.8rem center;
            }

            .form-group {
                margin-bottom: 1.5rem;
            }

            .form-group label {
                font-size: 0.95rem;
                margin-bottom: 0.6rem;
            }

            .radio-group, .checkbox-group {
                gap: 0.8rem;
            }

            .radio-option, .checkbox-option {
                padding: 0.8rem;
                min-height: 3rem;
            }

            .rating-card-header {
                padding: 0.8rem;
            }

            .rating-card-body {
                padding: 1rem;
            }

            .rating-card-title {
                font-size: 0.95rem;
            }
        }

        @media (max-width: 480px) {
            .form-control {
                padding: 0.7rem 1rem;
                font-size: 0.95rem;
                border-radius: 12px;
            }

            .form-group {
                margin-bottom: 1.2rem;
            }

            .form-group label {
                font-size: 0.9rem;
                margin-bottom: 0.5rem;
            }

            .radio-option, .checkbox-option {
                padding: 0.7rem;
                min-height: 2.8rem;
            }

            .rating-card-header {
                padding: 0.7rem;
            }

            .rating-card-body {
                padding: 0.8rem;
            }

            .rating-card-title {
                font-size: 0.9rem;
            }

            .rating-card-number {
                width: 1.8rem;
                height: 1.8rem;
                font-size: 0.9rem;
            }
        }

        /* Improved touch targets for mobile */
        @media (max-width: 768px) {
            .rating-label {
                width: 35px;
                height: 35px;
                font-size: 1rem;
            }

            .rating-radio-group {
                padding: 0.5rem 0.8rem;
                gap: 0.1rem;
                max-width: 100%;
            }

            .rating-options {
                gap: 0.1rem;
            }

            .btn-submit {
                padding: 1rem 2rem;
                font-size: 1.1rem;
                margin: 2rem auto;
                min-height: 3.5rem;
            }

            .mobile-nav-btn {
                width: 3.5rem;
                height: 3.5rem;
                font-size: 1.3rem;
            }
        }

        @media (max-width: 480px) {
            .rating-label {
                width: 30px;
                height: 30px;
                font-size: 0.9rem;
            }

            .rating-radio-group {
                padding: 0.3rem 0.6rem;
                gap: 0.1rem;
            }

            .rating-options {
                gap: 0.1rem;
            }

            .btn-submit {
                padding: 0.9rem 1.8rem;
                font-size: 1rem;
                margin: 1.5rem auto;
                min-height: 3.2rem;
            }

            .mobile-nav-btn {
                width: 3.2rem;
                height: 3.2rem;
                font-size: 1.2rem;
            }
        }

        /* Improved mobile form validation */
        .form-control.error {
            border: 2px solid var(--danger-color);
            animation: shake 0.5s;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }

        .error-message {
            color: var(--danger-color);
            font-size: 0.85rem;
            margin-top: 0.5rem;
            display: none;
            padding: 0.5rem;
            background-color: rgba(231, 76, 60, 0.1);
            border-radius: 5px;
        }

        /* Improved mobile form section transitions */
        .form-section {
            transition: all 0.3s ease;
        }

        .form-section.active {
            transform: translateY(0);
            opacity: 1;
        }

        .form-section:not(.active) {
            transform: translateY(20px);
            opacity: 0.8;
        }

        /* Improved mobile form progress indicator */
        .form-progress {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2rem;
            position: relative;
        }

        .form-progress::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            border-radius: 2px;
            z-index: 1;
        }

        .form-progress::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            height: 4px;
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            border-radius: 2px;
            z-index: 2;
            transition: width 0.3s ease;
        }

        .progress-step {
            width: 2rem;
            height: 2rem;
            border-radius: 50%;
            background: var(--bg-color);
            box-shadow: var(--shadow);
            display: flex;
            justify-content: center;
            align-items: center;
            font-weight: 600;
            color: var(--text-color);
            position: relative;
            z-index: 3;
            transition: all 0.3s ease;
        }

        .progress-step.active {
            background: var(--primary-color);
            color: white;
            transform: scale(1.1);
        }

        .progress-step.completed {
            background: var(--secondary-color);
            color: white;
        }

        @media (max-width: 768px) {
            .form-progress {
                margin-bottom: 1.5rem;
            }

            .progress-step {
                width: 1.8rem;
                height: 1.8rem;
                font-size: 0.9rem;
            }
        }

        @media (max-width: 480px) {
            .form-progress {
                margin-bottom: 1.2rem;
            }

            .progress-step {
                width: 1.6rem;
                height: 1.6rem;
                font-size: 0.8rem;
            }
        }

        @media (max-width: 768px) {
            .desktop-only {
                display: none !important; /* Added !important to override any other display settings */
            }
            
            .mobile-only {
                display: block;
            }
        }
    </style>
</head>
<body>
    <!-- Add loading overlay -->
    <div class="loading-overlay">
        <div class="loading-spinner"></div>
    </div>

    <div class="container">
        <form class="survey-form" method="POST" action="submit_alumni_survey.php" id="alumniSurveyForm" enctype="multipart/form-data">
            <!-- Part I: Personal Details -->
            <div class="form-section">
                <h3><i class="fas fa-user"></i> PART I: Alumni Personal Details</h3>
                
                <div class="form-group">
                    <label>Name of the Alumni</label>
                    <input type="text" class="form-control" name="name" required>
                </div>

                <div class="form-group">
                    <label>Gender</label>
                    <div class="radio-group">
                        <label class="radio-option">
                            <input type="radio" name="gender" value="female" required> Female
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="gender" value="male"> Male
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label>Year of Passing</label>
                    <input type="text" class="form-control" name="passing_year" required>
                </div>

                <div class="form-group">
                    <label>Degree/Branch</label>
                    <select name="degree" class="form-control" required>
                        <option value="">Select your degree</option>
                        
                        <!-- B.E. Degrees -->
                        <optgroup label="Bachelor of Engineering (B.E)">
                            <option value="B.E Computer Science and Engineering">B.E Computer Science and Engineering</option>
                            <option value="B.E Electronics and Communication Engineering">B.E Electronics and Communication Engineering</option>
                            <option value="B.E Mechanical Engineering">B.E Mechanical Engineering</option>
                            <option value="B.E Electrical and Electronics Engineering">B.E Electrical and Electronics Engineering</option>
                            <option value="B.E Civil Engineering">B.E Civil Engineering</option>
                            <option value="B.E Information Technology">B.E Information Technology</option>
                            <option value="B.E Instrumentation and Control Engineering">B.E Instrumentation and Control Engineering</option>
                            <option value="B.E Automobile Engineering">B.E Automobile Engineering</option>
                            <option value="B.E Production Engineering">B.E Production Engineering</option>
                            <option value="B.E Aeronautical Engineering">B.E Aeronautical Engineering</option>
                            <option value="B.E Marine Engineering">B.E Marine Engineering</option>
                        </optgroup>

                        <!-- B.Tech Degrees -->
                        <optgroup label="Bachelor of Technology (B.Tech)">
                            <option value="B.Tech Artificial Intelligence and Data Science">B.Tech Artificial Intelligence and Data Science</option>
                            <option value="B.Tech Artificial Intelligence and Machine Learning">B.Tech Artificial Intelligence and Machine Learning</option>
                            <option value="B.Tech Computer Science and Business Systems">B.Tech Computer Science and Business Systems</option>
                            <option value="B.Tech Chemical Engineering">B.Tech Chemical Engineering</option>
                            <option value="B.Tech Biotechnology">B.Tech Biotechnology</option>
                            <option value="B.Tech Food Technology">B.Tech Food Technology</option>
                        </optgroup>

                        <!-- M.E. Degrees -->
                        <optgroup label="Master of Engineering (M.E)">
                            <option value="M.E Computer Science and Engineering">M.E Computer Science and Engineering</option>
                            <option value="M.E Electronics and Communication Engineering">M.E Electronics and Communication Engineering</option>
                            <option value="M.E Mechanical Engineering">M.E Mechanical Engineering</option>
                            <option value="M.E Power Electronics and Drives">M.E Power Electronics and Drives</option>
                            <option value="M.E VLSI Design">M.E VLSI Design</option>
                            <option value="M.E Embedded Systems">M.E Embedded Systems</option>
                            <option value="M.E CAD/CAM">M.E CAD/CAM</option>
                        </optgroup>

                        <!-- M.Tech Degrees -->
                        <optgroup label="Master of Technology (M.Tech)">
                            <option value="M.Tech Artificial Intelligence and Data Science">M.Tech Artificial Intelligence and Data Science</option>
                            <option value="M.Tech Information Technology">M.Tech Information Technology</option>
                            <option value="M.Tech Biotechnology">M.Tech Biotechnology</option>
                        </optgroup>

                        <!-- MBA Degrees -->
                        <optgroup label="Master of Business Administration (MBA)">
                            <option value="MBA">MBA</option>
                        </optgroup>
                    </select>
                </div>

                <div class="form-group">
                    <label>Contact Address</label>
                    <textarea class="form-control" name="address" rows="3" required></textarea>
                </div>

                <div class="form-group">
                    <label>Mobile No</label>
                    <input type="tel" class="form-control" name="mobile" required>
                </div>

                <div class="form-group">
                    <label>Phone No (Res)</label>
                    <input type="tel" class="form-control" name="phone">
                </div>

                <div class="form-group">
                    <label>Personal Email-ID</label>
                    <input type="email" class="form-control" name="email" required>
                </div>

                <div class="form-group">
                    <label>Have you appeared for any competitive examination?</label>
                    <div class="radio-group">
                        <label class="radio-option">
                            <input type="radio" name="competitive_exam" value="yes"> Yes
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="competitive_exam" value="no"> No
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label>If Yes, Please select the exams</label>
                    <div class="checkbox-group">
                        <label class="radio-option">
                            <input type="checkbox" name="exams[]" value="GRE"> GRE
                        </label>
                        <label class="radio-option">
                            <input type="checkbox" name="exams[]" value="TOEFL"> TOEFL
                        </label>
                        <label class="radio-option">
                            <input type="checkbox" name="exams[]" value="UPSC"> UPSC
                        </label>
                        <label class="radio-option">
                            <input type="checkbox" name="exams[]" value="CAT"> CAT
                        </label>
                        <label class="radio-option">
                            <input type="checkbox" name="exams[]" value="GATE"> GATE
                        </label>
                        <label class="radio-option">
                            <input type="checkbox" name="exams[]" value="IAS/IPS"> IAS/IPS
                        </label>
                        <label class="radio-option">
                            <input type="checkbox" name="exams[]" value="GMAT"> GMAT
                        </label>
                        <label class="radio-option">
                            <input type="checkbox" name="exams[]" value="Others"> Others
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label>Present Status</label>
                    <div class="radio-group">
                        <label class="radio-option">
                            <input type="radio" name="present_status" value="employed" required> Employed
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="present_status" value="higher_studies"> Higher Studies
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="present_status" value="entrepreneur"> Entrepreneur
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="present_status" value="not_employed"> Not Employed
                        </label>
                    </div>
                </div>
            </div>

            <!-- Employment Details Section -->
            <div class="form-section" id="employment-section">
                <h3>Employment Details</h3>
                
                <div class="form-group">
                    <label>Designation</label>
                    <input type="text" class="form-control" name="designation">
                </div>

                <div class="form-group">
                    <label>Name of the Company</label>
                    <input type="text" class="form-control" name="company_name">
                </div>

                <div class="form-group">
                    <label>Company Address</label>
                    <textarea class="form-control" name="company_address" rows="3"></textarea>
                </div>

                <div class="form-group">
                    <label>Phone No (Off)</label>
                    <input type="tel" class="form-control" name="office_phone">
                </div>

                <div class="form-group">
                    <label>Official Email-ID</label>
                    <input type="email" class="form-control" name="official_email">
                </div>

                <div class="form-group">
                    <label>Please briefly describe about the responsibilities of your job</label>
                    <textarea class="form-control" name="job_responsibilities" rows="4"></textarea>
                </div>

                <div class="form-group">
                    <label>What is your progress in the employment in-terms of promotion?</label>
                    <select class="form-control" name="promotion_level">
                        <option value="">Select your level</option>
                        <option value="Junior Engineer">Junior Engineer</option>
                        <option value="Site Engineer">Site Engineer</option>
                        <option value="Design Engineer">Design Engineer</option>
                        <option value="Team Lead">Team Lead</option>
                        <option value="Project Manager">Project Manager</option>
                        <option value="Others">Others</option>
                    </select>
                </div>
            </div>

            <!-- Higher Studies Section -->
            <div class="form-section" id="higher-studies-section">
                <h3>Higher Studies Details</h3>
                
                <div class="form-group">
                    <label>Course 1</label>
                    <input type="text" class="form-control" name="course1" placeholder="Course Name">
                    <input type="text" class="form-control mt-2" name="institution1" placeholder="Institution Name">
                    <input type="text" class="form-control mt-2" name="passing_year1" placeholder="Year of Passing">
                </div>

                <div class="form-group">
                    <label>Course 2</label>
                    <input type="text" class="form-control" name="course2" placeholder="Course Name">
                    <input type="text" class="form-control mt-2" name="institution2" placeholder="Institution Name">
                    <input type="text" class="form-control mt-2" name="passing_year2" placeholder="Year of Passing">
                </div>
            </div>

            <!-- Self Employed Section -->
            <div class="form-section" id="entrepreneur-section">
                <h3>Self Employment Details</h3>
                
                <div class="form-group">
                    <label>Name of the Company</label>
                    <input type="text" class="form-control" name="own_company_name">
                </div>

                <div class="form-group">
                    <label>Nature of the Activity</label>
                    <input type="text" class="form-control" name="business_nature">
                </div>

                <div class="form-group">
                    <label>Address</label>
                    <textarea class="form-control" name="business_address" rows="3"></textarea>
                </div>

                <div class="form-group">
                    <label>Phone No (Company)</label>
                    <input type="tel" class="form-control" name="business_phone">
                </div>

                <div class="form-group">
                    <label>Email-ID / Website</label>
                    <input type="text" class="form-control" name="business_contact">
                </div>
            </div>

            <!-- Part II: PO Assessment -->
            <div class="form-section">
                <h3><i class="fas fa-chart-line"></i> PART II: Attainment of PO's</h3>
                <p class="scale-info">Please rate your satisfaction with the academic preparation you received.<br>
                1= Poor 2 = Fair 3 = Good 4 = Very Good 5 = Excellent</p>

                <!-- Desktop Rating Table -->
                <table class="rating-table desktop-only">
                    <thead>
                        <tr>
                            <th width="5%">S.NO</th>
                            <th width="60%">Statement</th>
                            <th width="35%">Rating</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($po_statements as $po): ?>
                            <tr>
                                <td><?php echo $po['po_number']; ?></td>
                                <td><?php echo htmlspecialchars($po['statement']); ?></td>
                                <td>
                                    <div class="rating-radio-group">
                                        <div class="rating-options">
                                            <?php for ($i = 5; $i >= 1; $i--): ?>
                                                <div class="rating-option">
                                                    <input type="radio" 
                                                           id="po_<?php echo $po['po_number']; ?>_<?php echo $i; ?>" 
                                                           name="po_<?php echo $po['po_number']; ?>" 
                                                           value="<?php echo $i; ?>" 
                                                           class="rating-input"
                                                           required>
                                                    <label for="po_<?php echo $po['po_number']; ?>_<?php echo $i; ?>" 
                                                           class="rating-label"><?php echo $i; ?></label>
                                                </div>
                                            <?php endfor; ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Mobile Rating Cards -->
                <div class="mobile-rating-cards mobile-only">
                    <?php foreach ($po_statements as $po): ?>
                        <div class="rating-card">
                            <div class="rating-card-header">
                                <span class="rating-card-number"><?php echo $po['po_number']; ?></span>
                                <h4 class="rating-card-title"><?php echo htmlspecialchars($po['statement']); ?></h4>
                            </div>
                            <div class="rating-card-body">
                                <div class="rating-radio-group">
                                    <div class="rating-options">
                                        <?php for ($i = 5; $i >= 1; $i--): ?>
                                            <div class="rating-option">
                                                <input type="radio" 
                                                       id="po_mobile_<?php echo $po['po_number']; ?>_<?php echo $i; ?>" 
                                                       name="po_<?php echo $po['po_number']; ?>" 
                                                       value="<?php echo $i; ?>" 
                                                       class="rating-input"
                                                       required>
                                                <label for="po_mobile_<?php echo $po['po_number']; ?>_<?php echo $i; ?>" 
                                                       class="rating-label"><?php echo $i; ?></label>
                                            </div>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Part III: PEO Assessment -->
            <div class="form-section">
                <h3><i class="fas fa-bullseye"></i> PART III: Attainment of PEO's</h3>
                <p class="scale-info">How well did your College experience prepare you for:<br>
                1= Poor 2 = Fair 3 = Good 4 = Very Good 5 = Excellent</p>

                <!-- Desktop Rating Table -->
                <table class="rating-table desktop-only">
                    <thead>
                        <tr>
                            <th width="5%">S.NO</th>
                            <th width="60%">Statement</th>
                            <th width="35%">Rating</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($peo_statements as $peo): ?>
                            <tr>
                                <td><?php echo $peo['peo_number']; ?></td>
                                <td><?php echo htmlspecialchars($peo['statement']); ?></td>
                                <td>
                                    <div class="rating-radio-group">
                                        <div class="rating-options">
                                            <?php for ($i = 5; $i >= 1; $i--): ?>
                                                <div class="rating-option">
                                                    <input type="radio" 
                                                           id="peo_<?php echo $peo['peo_number']; ?>_<?php echo $i; ?>" 
                                                           name="peo_<?php echo $peo['peo_number']; ?>" 
                                                           value="<?php echo $i; ?>" 
                                                           class="rating-input"
                                                           required>
                                                    <label for="peo_<?php echo $peo['peo_number']; ?>_<?php echo $i; ?>" 
                                                           class="rating-label"><?php echo $i; ?></label>
                                                </div>
                                            <?php endfor; ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Mobile Rating Cards -->
                <div class="mobile-rating-cards mobile-only">
                    <?php foreach ($peo_statements as $peo): ?>
                        <div class="rating-card">
                            <div class="rating-card-header">
                                <span class="rating-card-number"><?php echo $peo['peo_number']; ?></span>
                                <h4 class="rating-card-title"><?php echo htmlspecialchars($peo['statement']); ?></h4>
                            </div>
                            <div class="rating-card-body">
                                <div class="rating-radio-group">
                                    <div class="rating-options">
                                        <?php for ($i = 5; $i >= 1; $i--): ?>
                                            <div class="rating-option">
                                                <input type="radio" 
                                                       id="peo_mobile_<?php echo $peo['peo_number']; ?>_<?php echo $i; ?>" 
                                                       name="peo_<?php echo $peo['peo_number']; ?>" 
                                                       value="<?php echo $i; ?>" 
                                                       class="rating-input"
                                                       required>
                                                <label for="peo_mobile_<?php echo $peo['peo_number']; ?>_<?php echo $i; ?>" 
                                                       class="rating-label"><?php echo $i; ?></label>
                                            </div>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Part IV: PSO Assessment -->
            <div class="form-section">
                <h3><i class="fas fa-tasks"></i> PART IV: Attainment of PSO's</h3>
                <p class="scale-info">How well did your programme have impact on:<br>
                1= Poor 2 = Fair 3 = Good 4 = Very Good 5 = Excellent</p>

                <!-- Desktop Rating Table -->
                <table class="rating-table desktop-only">
                    <thead>
                        <tr>
                            <th width="5%">S.NO</th>
                            <th width="60%">Statement</th>
                            <th width="35%">Rating</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pso_statements as $pso): ?>
                            <tr>
                                <td><?php echo $pso['pso_number']; ?></td>
                                <td><?php echo htmlspecialchars($pso['statement']); ?></td>
                                <td>
                                    <div class="rating-radio-group">
                                        <div class="rating-options">
                                            <?php for ($i = 5; $i >= 1; $i--): ?>
                                                <div class="rating-option">
                                                    <input type="radio" 
                                                           id="pso_<?php echo $pso['pso_number']; ?>_<?php echo $i; ?>" 
                                                           name="pso_<?php echo $pso['pso_number']; ?>" 
                                                           value="<?php echo $i; ?>" 
                                                           class="rating-input"
                                                           required>
                                                    <label for="pso_<?php echo $pso['pso_number']; ?>_<?php echo $i; ?>" 
                                                           class="rating-label"><?php echo $i; ?></label>
                                                </div>
                                            <?php endfor; ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Mobile Rating Cards -->
                <div class="mobile-rating-cards mobile-only">
                    <?php foreach ($pso_statements as $pso): ?>
                        <div class="rating-card">
                            <div class="rating-card-header">
                                <span class="rating-card-number"><?php echo $pso['pso_number']; ?></span>
                                <h4 class="rating-card-title"><?php echo htmlspecialchars($pso['statement']); ?></h4>
                            </div>
                            <div class="rating-card-body">
                                <div class="rating-radio-group">
                                    <div class="rating-options">
                                        <?php for ($i = 5; $i >= 1; $i--): ?>
                                            <div class="rating-option">
                                                <input type="radio" 
                                                       id="pso_mobile_<?php echo $pso['pso_number']; ?>_<?php echo $i; ?>" 
                                                       name="pso_<?php echo $pso['pso_number']; ?>" 
                                                       value="<?php echo $i; ?>" 
                                                       class="rating-input"
                                                       required>
                                                <label for="pso_mobile_<?php echo $pso['pso_number']; ?>_<?php echo $i; ?>" 
                                                       class="rating-label"><?php echo $i; ?></label>
                                            </div>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Part V: General Assessment -->
            <div class="form-section">
                <h3><i class="fas fa-clipboard-list"></i> PART V: General Questions</h3>
                <p class="scale-info">With regard to each of the following, how satisfied are you with the undergraduate education you received at PEC<br>
                1= Poor 2 = Fair 3 = Good 4 = Very Good 5 = Excellent</p>

                <!-- Desktop Rating Table -->
                <table class="rating-table desktop-only">
                    <thead>
                        <tr>
                            <th width="5%">S.NO</th>
                            <th width="60%">Statement</th>
                            <th width="35%">Rating</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($general_statements as $question): ?>
                            <tr>
                                <td><?php echo $question['question_number']; ?></td>
                                <td><?php echo htmlspecialchars($question['statement']); ?></td>
                                <td>
                                    <div class="rating-radio-group">
                                        <div class="rating-options">
                                            <?php for ($i = 5; $i >= 1; $i--): ?>
                                                <div class="rating-option">
                                                    <input type="radio" 
                                                           id="general_<?php echo $question['question_number']; ?>_<?php echo $i; ?>" 
                                                           name="general_<?php echo $question['question_number']; ?>" 
                                                           value="<?php echo $i; ?>" 
                                                           class="rating-input"
                                                           required>
                                                    <label for="general_<?php echo $question['question_number']; ?>_<?php echo $i; ?>" 
                                                           class="rating-label"><?php echo $i; ?></label>
                                                </div>
                                            <?php endfor; ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Mobile Rating Cards -->
                <div class="mobile-rating-cards mobile-only">
                    <?php foreach ($general_statements as $question): ?>
                        <div class="rating-card">
                            <div class="rating-card-header">
                                <span class="rating-card-number"><?php echo $question['question_number']; ?></span>
                                <h4 class="rating-card-title"><?php echo htmlspecialchars($question['statement']); ?></h4>
                            </div>
                            <div class="rating-card-body">
                                <div class="rating-radio-group">
                                    <div class="rating-options">
                                        <?php for ($i = 5; $i >= 1; $i--): ?>
                                            <div class="rating-option">
                                                <input type="radio" 
                                                       id="general_mobile_<?php echo $question['question_number']; ?>_<?php echo $i; ?>" 
                                                       name="general_<?php echo $question['question_number']; ?>" 
                                                       value="<?php echo $i; ?>" 
                                                       class="rating-input"
                                                       required>
                                                <label for="general_mobile_<?php echo $question['question_number']; ?>_<?php echo $i; ?>" 
                                                       class="rating-label"><?php echo $i; ?></label>
                                            </div>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Additional General Questions -->
                <div class="form-group">
                    <label>Can you specify any training you undergone in AI&DS department, which has been useful for your career?</label>
                    <textarea class="form-control" name="useful_training" rows="4"></textarea>
                </div>

                <div class="form-group">
                    <label>Can you specify any course(s) that may be added to make graduates more competent and employable?</label>
                    <textarea class="form-control" name="suggested_courses" rows="4"></textarea>
                </div>

                <div class="form-group">
                    <label>Any suggestion that may be added to match with growing industry needs.</label>
                    <textarea class="form-control" name="industry_suggestions" rows="4"></textarea>
                </div>

                <div class="form-group">
                    <label>Remarks, if any:</label>
                    <textarea class="form-control" name="remarks" rows="4"></textarea>
                </div>

                <div class="form-group">
                    <label>Date</label>
                    <input type="date" class="form-control" name="submission_date" required>
                </div>
            </div>

            <button type="submit" class="btn-submit">
                <i class="fas fa-paper-plane"></i> Submit Survey
            </button>
        </form>
    </div>

    <!-- Mobile Navigation -->
    <div class="mobile-nav">
        <div class="mobile-nav-buttons">
            <button class="mobile-nav-btn" id="prevSection">
                <i class="fas fa-arrow-up"></i>
            </button>
            <button class="mobile-nav-btn" id="nextSection">
                <i class="fas fa-arrow-down"></i>
            </button>
            <button class="mobile-nav-btn" id="scrollTop">
                <i class="fas fa-chevron-up"></i>
            </button>
        </div>
    </div>

    <script>
        // Form validation and submission handling
        document.getElementById('alumniSurveyForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Show loading overlay
            document.querySelector('.loading-overlay').style.display = 'flex';
            
            // Disable submit button
            document.querySelector('.btn-submit').disabled = true;
            
            // Submit the form
            this.submit();
        });

        // Add animation effects to form controls
        document.querySelectorAll('.form-control').forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.style.transform = 'translateY(-5px)';
            });

            input.addEventListener('blur', function() {
                this.parentElement.style.transform = 'translateY(0)';
            });
        });

        // Handle Present Status section visibility
        document.addEventListener('DOMContentLoaded', function() {
            const presentStatusRadios = document.querySelectorAll('input[name="present_status"]');
            const employmentSection = document.getElementById('employment-section');
            const higherStudiesSection = document.getElementById('higher-studies-section');
            const entrepreneurSection = document.getElementById('entrepreneur-section');

            // Initially hide all sections
            employmentSection.style.display = 'none';
            higherStudiesSection.style.display = 'none';
            entrepreneurSection.style.display = 'none';

            // Function to handle section visibility
            function updateSectionVisibility(status) {
                employmentSection.style.display = status === 'employed' ? 'block' : 'none';
                higherStudiesSection.style.display = status === 'higher_studies' ? 'block' : 'none';
                entrepreneurSection.style.display = status === 'entrepreneur' ? 'block' : 'none';

                // Clear fields in hidden sections
                if (status !== 'employed') {
                    employmentSection.querySelectorAll('input, textarea, select').forEach(field => {
                        field.value = '';
                        if (field.type === 'radio' || field.type === 'checkbox') {
                            field.checked = false;
                        }
                    });
                }
                if (status !== 'higher_studies') {
                    higherStudiesSection.querySelectorAll('input, textarea, select').forEach(field => {
                        field.value = '';
                        if (field.type === 'radio' || field.type === 'checkbox') {
                            field.checked = false;
                        }
                    });
                }
                if (status !== 'entrepreneur') {
                    entrepreneurSection.querySelectorAll('input, textarea, select').forEach(field => {
                        field.value = '';
                        if (field.type === 'radio' || field.type === 'checkbox') {
                            field.checked = false;
                        }
                    });
                }
            }

            // Add event listeners to radio buttons
            presentStatusRadios.forEach(radio => {
                radio.addEventListener('change', function() {
                    updateSectionVisibility(this.value);
                });
            });

            // Check if a status is already selected (e.g., on page reload)
            const selectedStatus = document.querySelector('input[name="present_status"]:checked');
            if (selectedStatus) {
                updateSectionVisibility(selectedStatus.value);
            }
        });

        // Handle competitive exam section visibility
        document.addEventListener('DOMContentLoaded', function() {
            const competitiveExamRadios = document.querySelectorAll('input[name="competitive_exam"]');
            const examCheckboxes = document.querySelector('.checkbox-group');

            // Initially hide exam checkboxes
            examCheckboxes.style.display = 'none';

            competitiveExamRadios.forEach(radio => {
                radio.addEventListener('change', function() {
                    examCheckboxes.style.display = this.value === 'yes' ? 'grid' : 'none';
                    if (this.value === 'no') {
                        examCheckboxes.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                            checkbox.checked = false;
                        });
                    }
                });
            });

            // Check initial state
            const selectedExam = document.querySelector('input[name="competitive_exam"]:checked');
            if (selectedExam) {
                examCheckboxes.style.display = selectedExam.value === 'yes' ? 'grid' : 'none';
            }
        });

        // Mobile navigation functionality
        document.addEventListener('DOMContentLoaded', function() {
            const formSections = document.querySelectorAll('.form-section');
            const prevBtn = document.getElementById('prevSection');
            const nextBtn = document.getElementById('nextSection');
            const scrollTopBtn = document.getElementById('scrollTop');
            let currentSectionIndex = 0;

            // Function to scroll to a specific section
            function scrollToSection(index) {
                if (index >= 0 && index < formSections.length) {
                    formSections[index].scrollIntoView({ behavior: 'smooth' });
                    currentSectionIndex = index;
                    updateNavButtons();
                }
            }

            // Function to update navigation buttons state
            function updateNavButtons() {
                prevBtn.disabled = currentSectionIndex === 0;
                nextBtn.disabled = currentSectionIndex === formSections.length - 1;
                
                // Update button styles
                prevBtn.style.opacity = currentSectionIndex === 0 ? '0.5' : '1';
                nextBtn.style.opacity = currentSectionIndex === formSections.length - 1 ? '0.5' : '1';
            }

            // Add event listeners to navigation buttons
            prevBtn.addEventListener('click', function() {
                scrollToSection(currentSectionIndex - 1);
            });

            nextBtn.addEventListener('click', function() {
                scrollToSection(currentSectionIndex + 1);
            });

            scrollTopBtn.addEventListener('click', function() {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });

            // Initialize navigation buttons
            updateNavButtons();

            // Add scroll event listener to update current section
            window.addEventListener('scroll', function() {
                const scrollPosition = window.scrollY + window.innerHeight / 2;
                
                formSections.forEach((section, index) => {
                    const sectionTop = section.offsetTop;
                    const sectionBottom = sectionTop + section.offsetHeight;
                    
                    if (scrollPosition >= sectionTop && scrollPosition <= sectionBottom) {
                        currentSectionIndex = index;
                        updateNavButtons();
                    }
                });
            });

            // Add touch swipe support for mobile
            let touchStartY = 0;
            let touchEndY = 0;

            document.addEventListener('touchstart', function(e) {
                touchStartY = e.touches[0].clientY;
            }, false);

            document.addEventListener('touchend', function(e) {
                touchEndY = e.changedTouches[0].clientY;
                handleSwipe();
            }, false);

            function handleSwipe() {
                const swipeDistance = touchStartY - touchEndY;
                const minSwipeDistance = 50;

                if (Math.abs(swipeDistance) > minSwipeDistance) {
                    if (swipeDistance > 0) {
                        // Swipe up - go to next section
                        scrollToSection(currentSectionIndex + 1);
                    } else {
                        // Swipe down - go to previous section
                        scrollToSection(currentSectionIndex - 1);
                    }
                }
            }
        });

        // Form validation for mobile
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('alumniSurveyForm');
            const requiredFields = form.querySelectorAll('[required]');
            
            // Add validation on blur
            requiredFields.forEach(field => {
                field.addEventListener('blur', function() {
                    validateField(this);
                });
            });
            
            // Validate field function
            function validateField(field) {
                if (field.value.trim() === '') {
                    field.classList.add('error');
                    
                    // Create error message if it doesn't exist
                    let errorMsg = field.nextElementSibling;
                    if (!errorMsg || !errorMsg.classList.contains('error-message')) {
                        errorMsg = document.createElement('div');
                        errorMsg.classList.add('error-message');
                        errorMsg.textContent = 'This field is required';
                        field.parentNode.insertBefore(errorMsg, field.nextSibling);
                    }
                    
                    errorMsg.style.display = 'block';
                } else {
                    field.classList.remove('error');
                    
                    // Hide error message if it exists
                    const errorMsg = field.nextElementSibling;
                    if (errorMsg && errorMsg.classList.contains('error-message')) {
                        errorMsg.style.display = 'none';
                    }
                }
            }
            
            // Validate form on submit
            form.addEventListener('submit', function(e) {
                let isValid = true;
                
                requiredFields.forEach(field => {
                    validateField(field);
                    if (field.classList.contains('error')) {
                        isValid = false;
                    }
                });
                
                if (!isValid) {
                    e.preventDefault();
                    
                    // Scroll to first error
                    const firstError = form.querySelector('.error');
                    if (firstError) {
                        firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
            });
        });

        // Optimize performance for mobile
        document.addEventListener('DOMContentLoaded', function() {
            // Lazy load images and iframes
            const lazyElements = document.querySelectorAll('img[data-src], iframe[data-src]');
            
            if ('IntersectionObserver' in window) {
                const lazyObserver = new IntersectionObserver((entries, observer) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            const lazyElement = entry.target;
                            lazyElement.src = lazyElement.dataset.src;
                            lazyElement.removeAttribute('data-src');
                            lazyObserver.unobserve(lazyElement);
                        }
                    });
                });
                
                lazyElements.forEach(lazyElement => {
                    lazyObserver.observe(lazyElement);
                });
            } else {
                // Fallback for browsers that don't support IntersectionObserver
                lazyElements.forEach(lazyElement => {
                    lazyElement.src = lazyElement.dataset.src;
                    lazyElement.removeAttribute('data-src');
                });
            }
            
            // Debounce scroll events for better performance
            function debounce(func, wait) {
                let timeout;
                return function() {
                    const context = this;
                    const args = arguments;
                    clearTimeout(timeout);
                    timeout = setTimeout(() => {
                        func.apply(context, args);
                    }, wait);
                };
            }
            
            // Apply debounce to scroll event
            const debouncedScroll = debounce(() => {
                // Your scroll handling code here
            }, 100);
            
            window.addEventListener('scroll', debouncedScroll);

            // Synchronize desktop and mobile rating inputs
            syncRatingInputs();
        });

        // Function to synchronize desktop and mobile rating inputs
        function syncRatingInputs() {
            // Sync PO ratings
            document.querySelectorAll('[id^="po_mobile_"]').forEach(mobileInput => {
                mobileInput.addEventListener('change', function() {
                    const poNumber = this.id.split('_')[2];
                    const rating = this.value;
                    const desktopInput = document.getElementById(`po_${poNumber}_${rating}`);
                    if (desktopInput) {
                        desktopInput.checked = true;
                    }
                });
            });

            document.querySelectorAll('[id^="po_"][id$="1"], [id^="po_"][id$="2"], [id^="po_"][id$="3"], [id^="po_"][id$="4"], [id^="po_"][id$="5"]').forEach(desktopInput => {
                if (!desktopInput.id.includes('mobile')) {
                    desktopInput.addEventListener('change', function() {
                        const idParts = this.id.split('_');
                        const poNumber = idParts[1];
                        const rating = idParts[2];
                        const mobileInput = document.getElementById(`po_mobile_${poNumber}_${rating}`);
                        if (mobileInput) {
                            mobileInput.checked = true;
                        }
                    });
                }
            });

            // Sync PEO ratings
            document.querySelectorAll('[id^="peo_mobile_"]').forEach(mobileInput => {
                mobileInput.addEventListener('change', function() {
                    const peoNumber = this.id.split('_')[2];
                    const rating = this.value;
                    const desktopInput = document.getElementById(`peo_${peoNumber}_${rating}`);
                    if (desktopInput) {
                        desktopInput.checked = true;
                    }
                });
            });

            document.querySelectorAll('[id^="peo_"][id$="1"], [id^="peo_"][id$="2"], [id^="peo_"][id$="3"], [id^="peo_"][id$="4"], [id^="peo_"][id$="5"]').forEach(desktopInput => {
                if (!desktopInput.id.includes('mobile')) {
                    desktopInput.addEventListener('change', function() {
                        const idParts = this.id.split('_');
                        const peoNumber = idParts[1];
                        const rating = idParts[2];
                        const mobileInput = document.getElementById(`peo_mobile_${peoNumber}_${rating}`);
                        if (mobileInput) {
                            mobileInput.checked = true;
                        }
                    });
                }
            });

            // Sync PSO ratings
            document.querySelectorAll('[id^="pso_mobile_"]').forEach(mobileInput => {
                mobileInput.addEventListener('change', function() {
                    const psoNumber = this.id.split('_')[2];
                    const rating = this.value;
                    const desktopInput = document.getElementById(`pso_${psoNumber}_${rating}`);
                    if (desktopInput) {
                        desktopInput.checked = true;
                    }
                });
            });

            document.querySelectorAll('[id^="pso_"][id$="1"], [id^="pso_"][id$="2"], [id^="pso_"][id$="3"], [id^="pso_"][id$="4"], [id^="pso_"][id$="5"]').forEach(desktopInput => {
                if (!desktopInput.id.includes('mobile')) {
                    desktopInput.addEventListener('change', function() {
                        const idParts = this.id.split('_');
                        const psoNumber = idParts[1];
                        const rating = idParts[2];
                        const mobileInput = document.getElementById(`pso_mobile_${psoNumber}_${rating}`);
                        if (mobileInput) {
                            mobileInput.checked = true;
                        }
                    });
                }
            });

            // Sync General ratings
            document.querySelectorAll('[id^="general_mobile_"]').forEach(mobileInput => {
                mobileInput.addEventListener('change', function() {
                    const questionNumber = this.id.split('_')[2];
                    const rating = this.value;
                    const desktopInput = document.getElementById(`general_${questionNumber}_${rating}`);
                    if (desktopInput) {
                        desktopInput.checked = true;
                    }
                });
            });

            document.querySelectorAll('[id^="general_"][id$="1"], [id^="general_"][id$="2"], [id^="general_"][id$="3"], [id^="general_"][id$="4"], [id^="general_"][id$="5"]').forEach(desktopInput => {
                if (!desktopInput.id.includes('mobile')) {
                    desktopInput.addEventListener('change', function() {
                        const idParts = this.id.split('_');
                        const questionNumber = idParts[1];
                        const rating = idParts[2];
                        const mobileInput = document.getElementById(`general_mobile_${questionNumber}_${rating}`);
                        if (mobileInput) {
                            mobileInput.checked = true;
                        }
                    });
                }
            });
        }
    </script>
    <?php include 'footer.php'; ?>
</body>
</html> 