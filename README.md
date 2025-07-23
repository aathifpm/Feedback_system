
# Feedback System

## Overview

The **Feedback System** is a robust, web-based application designed and developed to facilitate comprehensive feedback collection, student and faculty management, attendance tracking and survey analytics for educational institutions. It aims to serve as a centralized platform for improving academic performance and organizational decision-making through structured data and analysis.

> **Important:**  
> This project is the intellectual property of **Panimalar Engineering College, Chennai**.  
> **Use, copying, or distribution is _strictly prohibited_ without explicit, prior written permission from the institution.**


## Live Demo & Video

- 🌐 **Live Website**: [Visit Live Feedback System](https://ads-panimalar.in)
- 🎥 **YouTube Demo**: [Watch on YouTube](https://youtu.be/GPceUI_YHow)

> Note: Access to the live system may be restricted to authorized users. Contact the institution for credentials or demo access.
## Table of Contents

- [Features](#features)
- [Architecture](#architecture)
- [Installation](#installation)
- [Usage](#usage-scenarios)
- [File Structure](#file-structure)
- [Technical Details](#technical-details)
- [License](#license)
- [Contact](#contact)

---

## Features

### Multi-Role Authentication

- Secure, dedicated login portals for:
  - **Administrators**
  - **Faculty**
  - **Students**
  - **Heads of Department (HODs)**

### Feedback & Survey Management

- **Faculty Feedback:** Collection and analytics of student evaluations for faculty.
- **Student Feedback:** Peer-to-peer and self-assessment tools.
- **Alumni & Exit Surveys:** Structured forms for passing-out students and alumni.
- **Survey Analytics:** Real-time and historical analysis of survey responses.

### Attendance Tracking

- **Real-Time Attendance:** Mark and monitor attendance for classes, departments and training.
- **Barcode Integration:** Quick attendance marking using barcode scanning.

### Comprehensive Reporting & Export

- Generate detailed reports by faculty, student, department, or section.
- Export analytics and attendance in Excel format for external analysis.
- Download survey and feedback reports in various formats.

### Profile & Academic Management

- Edit and manage profiles for students and faculty.
- Track education, certifications, skills, experience and project portfolios.
- Support for recruiter views, promoting students and integrating placement functionalities.

### Administration Tools

- Manage users, roles and access rights.
- Centralized dashboard for quick overviews.
- Password management and recovery modules.
- Batch and section management.

---

## Architecture

- **Backend:** PHP (primary), with legacy Hack components  
- **Frontend:** HTML, CSS, JavaScript (within PHP interfaces)  
- **Database:** MySQL/MariaDB (via .sql files)  
- **Package Management:** Composer

### Key Directories & Files

- `admin/`, `includes/`, `blog/` â€” Modular code organization  
- `.sql` files for initial database setup and migrations  
- PHP scripts for every major function: feedback, attendance, analytics, profile and reports

---

## Installation

> **Note:**  
> Installation and usage are **restricted**.  
> If you are an authorized developer or administrator, follow these steps:

1. **Clone the repository**  
   ```bash
   git clone https://github.com/aathifpm/Feedback_system.git
   cd Feedback_system
   ```

2. **Set Up the Database**  
   - Import `db_setup.sql` or relevant `.sql` files into your MySQL/MariaDB server.  
   - Configure database credentials in your configuration file or within includes.

3. **Install Dependencies**  
   ```bash
   composer install
   ```

4. **Configure Web Server**  
   - Ensure PHP 7.4+ and Apache/Nginx are installed and configured.  
   - Adjust file permissions as necessary for uploads and logging.

5. **Set Branding**  
   - Add your `college_logo.png` for institutional branding.

6. **Create Admin User**  
   - Use `create_admin.php` to set up the first administrator account.

---

## Usage Scenarios

- **Administrators**:  
  Manage all faculty, student and departmental data. Generate detailed feedback and attendance analytics.

- **Faculty Members**:  
  Access and analyze student feedback, monitor attendance, download performance reports.

- **Students**:  
  Submit feedback, take surveys, update personal profiles, track personal attendance and view progress.

- **HODs/Department Admins**:  
  Oversee departmental metrics, manage courses and review comprehensive reports.

---

## File Structure

```
Feedback_system/
admin/              # Administrative management modules
blog/               # Blog and information modules
font/               # Font assets
includes/           # Shared includes for code reuse
*.php               # Functionality scripts
*.sql               # Database schemas and seeds
composer.json       # PHP dependency manager
college_logo.png    # Branding asset
README.md           # Project documentation
```

---

## Technical Details

- **Backend Language:** PHP 99.7%, Hack 0.3%
- **Security:**  
  - Encrypted password storage  
  - Secure session and access control  
  - Password recovery modules
- **Exports:** Excel/CSV export of analytics, attendance, feedback

---

## License

**Copyright & License**

The source code, design and all associated resources in this repository are the **exclusive property** of **Panimalar Engineering College, Chennai**.  
All rights reserved.

- **Unauthorized use or copying of any part of this software is strictly prohibited.**
- The code, database schema and documentation may NOT be reproduced, distributed, or used for any commercial or non-commercial purpose without prior written consent from **Panimalar Engineering College, Chennai**.
- No right, title, or interest in or to the software or any associated intellectual property rights is granted except as expressly authorized by the institution.

---

## Contact

For permissions, questions, or collaborations, please contact:

**Panimalar Engineering College, Chennai**  
[email : aathifpm123@gmail.com](Mail)
[https://panimalar.ac.in]
[https://ads.panimalar.in]

---
