# Project Hub: Campus Project Management System 🎓

Project Hub is a centralized web-based platform designed to automate and streamline the lifecycle of academic projects at APSIT. It facilitates seamless collaboration between Students, Project Guides, Department Heads, and Administrators.

## 🚀 Key Features
* **Role-Based Dashboards**: Custom secure interfaces for Students, Guides, Heads, and Admins.
* **Project Lifecycle Management**: Automates team formation, project registration, and final submissions.
* **Student Tracking**: Integrated lookup for student progress using Moodle IDs.
* **System Hardening**: Advanced database migration scripts for launch-ready security and member management.
* **Administrative Controls**: Dynamic form settings and end-of-year data transfer/archiving tools.

## 🛠️ Tech Stack
* **Backend**: PHP (Core)
* **Database**: MySQL
* **Frontend**: HTML5, CSS3, JavaScript (Bootstrap Framework)
* **Server**: Apache (XAMPP / WAMP)

## 🔧 Installation & Setup
1. **Clone/Download**: Place the `Project-Hub` folder into your `htdocs` directory.
2. **Database Import**: 
   * Create a database named `project_hub_db`.
   * Import `/sql/project_hub_db_(6).sql` into phpMyAdmin.
   * (Optional) Run `migration` scripts for additional feature hardening.
3. **Configuration**: Update `core/db.php` or `core/config.php` with your local MySQL credentials.
4. **Launch**: Open `http://localhost/Project-Hub/index.php` in your browser.

## 📂 Key File Breakdown
* **`admin_transfer.php`**: Handles end-of-year student promotion and project archiving.
* **`migration_launch_hardening.sql`**: Script for securing the database before production deployment.
* **`bootstrap.php`**: Ensures only authorized users access specific dashboard roles.
## 📂 Required Directory Permissions
After downloading the project, ensure the following folders exist in the root directory and have **Write Permissions** enabled so the PHP scripts can store data:
* `/logs`: Stores system activity and error tracking.
* `/uploads`: Stores project documents and student submissions.

## 👤 Author
* **Engineering Student** at A.P. Shah Institute of Technology (APSIT).
