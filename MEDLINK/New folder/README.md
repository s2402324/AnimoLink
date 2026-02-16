# MediClear - Medical Certification System
## University of St. La Salle - Bacolod

### 📋 Project Overview
A PHP-based medical certification system for students and clinic staff at USLS Bacolod.

---

## 🎨 ASSET FILE MAPPING

**CRITICAL:** You need to create an `assets` folder and place your uploaded files with these exact names:

### File Name Conversions:
```
YOUR UPLOADED FILES          →    RENAME TO (in assets folder)
=====================================    ================================
2.png                        →    assets/2.jpg (Login background - blurred campus)
BG_FOR_LOGIN.png            →    assets/white-logo.png (White logo for login page)
LOGO.png                    →    assets/green-logo.png (Green logo for dashboards)
```

### Where Each Asset is Used:

1. **`assets/2.jpg`** (Your file: `2.png`)
   - Used in: `index.php` line 28
   - Purpose: Full-screen blurred background for login page
   - CSS: `.login-background { background-image: url('assets/2.jpg'); }`

2. **`assets/white-logo.png`** (Your file: `BG_FOR_LOGIN.png`)
   - Used in: `index.php` line 34
   - Purpose: White USLS logo displayed on login card
   - Recommended max-width: 250px

3. **`assets/green-logo.png`** (Your file: `LOGO.png`)
   - Used in: `student_dashboard.php` line 39
   - Used in: `clinic_dashboard.php` line 39
   - Purpose: Green USLS logo in navigation bar
   - Recommended height: 50px

### Additional Images You Uploaded (Not Currently Used):
- `3.png` - Can be used for future features
- `4.png` - Can be used for future features

---

## 📁 File Structure

```
mediclear/
│
├── index.php                 # Login page with role selection
├── student_dashboard.php     # Student dashboard view
├── clinic_dashboard.php      # Clinic staff dashboard view
├── style.css                 # All styling (USLS Green theme)
├── script.js                 # Interactive features
│
└── assets/                   # CREATE THIS FOLDER
    ├── 2.jpg                 # Background image
    ├── white-logo.png        # White logo for login
    └── green-logo.png        # Green logo for dashboards
```

---

## 🚀 Setup Instructions

### 1. Create Assets Folder
```bash
mkdir assets
```

### 2. Move and Rename Your Files
```bash
# Copy your uploaded files to the assets folder with correct names:
cp 2.png assets/2.jpg
cp BG_FOR_LOGIN.png assets/white-logo.png
cp LOGO.png assets/green-logo.png
```

### 3. Set Up Local Server
You need a PHP environment. Choose one:

**Option A: XAMPP (Recommended for Windows)**
```
1. Download XAMPP from https://www.apachefriends.org/
2. Install and start Apache
3. Copy all files to C:\xampp\htdocs\mediclear\
4. Visit: http://localhost/mediclear/
```

**Option B: MAMP (Recommended for Mac)**
```
1. Download MAMP from https://www.mamp.info/
2. Install and start servers
3. Copy all files to /Applications/MAMP/htdocs/mediclear/
4. Visit: http://localhost:8888/mediclear/
```

**Option C: PHP Built-in Server (Quick Testing)**
```bash
# Navigate to your project folder
cd mediclear

# Start PHP server
php -S localhost:8000

# Visit: http://localhost:8000
```

---

## 🎯 Features Implemented

### ✅ Login Page (`index.php`)
- Visual role toggle (Student / Clinic Staff)
- Form validation
- Session-based authentication
- Responsive design with blurred background
- Redirects based on role selection

### ✅ Student Dashboard (`student_dashboard.php`)
- Statistics cards (Total, Pending, Approved, Rejected)
- Submit medical request button (with modal placeholder)
- Recent requests display
- View certificate functionality
- Logout feature

### ✅ Clinic Dashboard (`clinic_dashboard.php`)
- All requests overview
- Request status tracking (Pending, Ongoing, Rejected)
- Student information display
- Approve/Reject functionality
- Validity date tracking
- Interactive buttons with confirmation dialogs

### ✅ Design Features
- USLS brand colors (#1B5E20 green)
- Responsive layout (mobile-friendly)
- Modern UI with smooth transitions
- Clean, professional appearance
- Accessibility considerations

---

## 🎨 Color Palette

```css
Primary Green:    #1B5E20
Light Green:      #2E7D32
Background Gray:  #F5F5F5
White:           #FFFFFF
Text Dark:       #212121
Text Gray:       #757575
Orange:          #FF9800
Red:             #D32F2F
```

---

## 🔧 Next Steps for Database Integration

### 1. Create Database
```sql
CREATE DATABASE mediclear;

CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id VARCHAR(50) UNIQUE,
    password VARCHAR(255),
    role ENUM('student', 'clinic'),
    name VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE medical_requests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    request_id VARCHAR(50) UNIQUE,
    student_id VARCHAR(50),
    illness VARCHAR(100),
    symptoms TEXT,
    illness_date DATE,
    submitted_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('pending', 'approved', 'rejected', 'ongoing'),
    approved_date DATE,
    valid_until DATE,
    rejection_reason TEXT,
    FOREIGN KEY (student_id) REFERENCES users(user_id)
);
```

### 2. Create Database Connection File (`config.php`)
```php
<?php
$host = 'localhost';
$dbname = 'mediclear';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>
```

### 3. Update PHP Files
- Replace mock data arrays with database queries
- Add proper password hashing (use `password_hash()` and `password_verify()`)
- Implement AJAX for approve/reject actions
- Add form submission handling for new requests

---

## 📱 Testing Credentials (Mock Data)

**Student Login:**
- ID: Any text (e.g., "2023-0001")
- Password: Any text
- Role: Select "Student"

**Clinic Staff Login:**
- ID: Any text (e.g., "CLINIC001")
- Password: Any text
- Role: Select "Clinic Staff"

*Note: Currently accepts any credentials since database is not connected*

---

## 🐛 Known Limitations (To Be Implemented)

1. ❌ No actual database connection (uses mock data)
2. ❌ No password encryption
3. ❌ No email notifications
4. ❌ No file upload for medical documents
5. ❌ No PDF certificate generation
6. ❌ No search/filter functionality
7. ❌ No pagination for large datasets
8. ❌ No admin panel

---

## 📝 Assignment Notes

**Course:** BSIT 2nd Year - PHP & Web Applications
**Technology Stack:**
- Frontend: HTML5, CSS3, JavaScript (ES6)
- Backend: PHP 7.4+
- Design: Custom CSS (No frameworks)
- Database: MySQL (to be integrated)

**Code Quality:**
- Clean, commented code
- Semantic HTML
- Modular CSS with CSS variables
- Vanilla JavaScript (no jQuery)
- PHP best practices with session management

---

## 🎓 Grading Criteria Addressed

✅ **PHP Implementation:** All pages use .php extension with backend logic
✅ **CSS Styling:** Custom stylesheet matching design specifications
✅ **JavaScript Interactivity:** Form validation, animations, button handlers
✅ **Responsive Design:** Mobile-friendly layout
✅ **Code Organization:** Separate files for structure, style, behavior
✅ **User Experience:** Intuitive navigation, clear feedback
✅ **Visual Design:** Professional appearance matching USLS branding

---

## 📞 Support

For questions about this project:
- Check code comments for explanations
- Review this README for setup steps
- Test all features before database integration

---

## 📄 License

Academic project for University of St. La Salle - Bacolod
BSIT Program - 2nd Year

---

**Last Updated:** February 5, 2026
**Version:** 1.0.0
**Status:** Ready for Database Integration
