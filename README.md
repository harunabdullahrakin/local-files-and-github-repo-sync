
# PHP Git Sync & Upload Manager

A lightweight, dual-script solution for managing files between a PHP server (e.g., shared hosting) and a GitHub repository without needing SSH access or complex CI/CD pipelines.

## 📂 Included Scripts

1.  **`sync.php` (Downloader / Deployer)**
    * **Direction:** GitHub Repo $\rightarrow$ Local Server.
    * **Mechanism:** Uses GitHub REST API (cURL). No `git` installation required on the server.
    * **Features:**
        * Scans for differences (New files, Modified files, Deleted files).
        * Compares file hashes to ensure data integrity.
        * Modern Tailwind CSS Dashboard.
        * Selective syncing (choose which files to update).
        * Cleans up empty directories automatically.

2.  **`Upload.php` (Uploader / Backup)**
    * **Direction:** Local Server $\rightarrow$ GitHub Repo.
    * **Mechanism:** Uses `shell_exec` to run Git commands.
    * **Features:**
        * Initializes a git repo locally if one doesn't exist.
        * Commits all current files.
        * **Force Pushes** to the remote repository (mirrors local state to remote).
        * Terminal-style log output.

---

## ⚙️ Prerequisites

* **PHP 7.4 - 8.1+**
* **Write Permissions:** The script needs permission to write files and create folders in the root directory.
* **Extensions:**
    * `cURL` (Required for `sync.php`).
    * `shell_exec` / `exec` (Required for `Upload.php`). *Note: Some shared hosting providers disable this.*
* **GitHub Token:** A Personal Access Token (Classic) or Fine-grained Token.

---

##  Setup & Configuration

### 1. Configure `sync.php`

Open `sync.php` and edit the variables at the top of the file:

```php
$OWNER = 'your_github_username';
$REPO  = 'your_repo_name';
$TOKEN = 'ghp_your_personal_access_token_here';

// Optional: Files/Folders to ignore during sync
$EXCLUDE = [
    '.git',
    'config.php', // Add sensitive files here
    'sync.php',
    'Upload.php'
];

2. Configure Upload.php
Open Upload.php and edit the variables at the top:
$gitUser = 'your_github_username';
$gitRepo = 'your_repo_name'; 
$gitBranch = 'main'; // or 'master'
$gitToken = 'ghp_your_personal_access_token_here'; 

$committerName  = "Server Bot";
$committerEmail = "bot@yourdomain.com";

📖 Usage Guide
Using sync.php (To Deploy/Update Site)
 * Upload sync.php to your server.
 * Navigate to https://your-site.com/sync.php.
 * Click "Check for Updates".
 * The script will analyze the difference between your server and GitHub.
 * Review the lists:
   * New Files: Files on GitHub not on your server.
   * Modified: Files that differ in content.
   * Orphans: Files on your server that are NOT on GitHub (will be deleted).
 * Select the checkboxes for the files you want to process.
 * Click "Execute Sync".
Using Upload.php (To Save/Push Changes)
 * Navigate to https://your-site.com/Upload.php.
 * Review the warning (Ensure .gitignore is set up if you have secrets).
 * Click "Start Upload".
 * Watch the console output. The script will:
   * Initialize Git (if needed).
   * Add all files.
   * Commit with a timestamp.
   * Force push to GitHub.
⚠️ Important Security Warnings
 * Protect These Files:
   These scripts contain your GitHub Personal Access Token. If a stranger accesses these files, they can modify your repository.
   * Best Practice: Rename the files to something random (e.g., sync_x92mz.php) or password protect the directory using .htaccess.
   * Delete after use: If you don't need them often, delete them from the server after use.
 * Force Push (Upload.php):
   Upload.php uses --force. This means if you have changes on GitHub that are not on your local server, the GitHub changes will be overwritten/lost. It treats the Local Server as the "Source of Truth."
 * Data Loss (sync.php):
   If you sync "Orphan" files in sync.php, it will delete those files from your server. Ensure you have backups before deleting files.
📄 License
uh just remember my name thats enough.


