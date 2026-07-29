# วิธีการขึ้นโฮสระบบบน AlwaysData ด้วย Git

## 1. เตรียมโปรเจกต์สำหรับ Git

```bash
# เข้าไปในโฟลเดอร์โปรเจกต์
cd C:\xampp\htdocs\Rental

# เริ่มต้น git repository
git init

# เพิ่มไฟล์ทั้งหมด
git add .

# Commit ครั้งแรก
git commit -m "Initial commit"
```

สร้างไฟล์ `.gitignore` ในโฟลเดอร์ root:

```
# .gitignore
/vendor/
/node_modules/
/.env
*.log
.DS_Store
Thumbs.db
config/database.php
```

## 2. สร้าง Repository บน GitHub/GitLab

1. ไปที่ github.com หรือ gitlab.com
2. สร้าง repository ใหม่ (ชื่อ: rental-system หรือชื่อที่ต้องการ)
3. Copy URL ของ repository (เช่น: `https://github.com/username/rental-system.git`)

## 3. เชื่อมโยงกับ Remote Repository

```bash
# เพิ่ม remote repository
git remote add origin https://github.com/username/rental-system.git

# หรือถ้ามีอยู่แล้วและต้องการเปลี่ยน
git remote set-url origin https://github.com/username/rental-system.git

# พุชไปยัง remote repository
git push -u origin master
# หรือ
git push -u origin main
```

## 4. ตั้งค่า AlwaysData

### 4.1 สร้าง Account บน AlwaysData
1. ไปที่ alwaysdata.com
2. สมัครสมาชิกและสร้าง account

### 4.2 สร้าง Website
1. ไปที่เมนู "Websites"
2. คลิก "Add a website"
3. ตั้งค่า:
   - **Domain**: เลือก domain หรือใช้ subdomain ฟรี
   - **Type**: PHP
   - **Version**: เลือก PHP version (เช่น 8.1, 8.2)
   - **Path**: `/www`

### 4.3 เชื่อมต่อ Git บน AlwaysData
1. ไปที่เมนู "Git"
2. คลิก "Add a git repository"
3. ตั้งค่า:
   - **Repository URL**: `https://github.com/username/rental-system.git`
   - **Branch**: `master` หรือ `main`
   - **Path**: `/www`
4. คลิก "Add"

### 4.4 ตั้งค่า Database
1. ไปที่เมนู "Databases"
2. สร้าง MySQL database ใหม่
3. จด username, password, และ database name

## 5. แก้ไขไฟล์ Database Configuration

สร้างไฟล์ `config/database.php` บน AlwaysData:

```php
<?php
// config/database.php

$host = 'mysql-username.alwaysdata.net'; // จาก AlwaysData
$dbname = 'username_database_name';      // จาก AlwaysData
$username = 'username';                   // จาก AlwaysData
$password = 'your_password';             // จาก AlwaysData

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

define('BASE_URL', 'https://your-domain.alwaysdata.net/');
?>
```

## 6. อัปโหลด Database

```bash
# Export database จาก local
mysqldump -u root -p rental > rental_backup.sql

# Import ไปยัง AlwaysData (ผ่าน SSH หรือ phpMyAdmin)
mysql -h mysql-username.alwaysdata.net -u username -p username_database_name < rental_backup.sql
```

หรือใช้ phpMyAdmin บน AlwaysData:
1. ไปที่เมนู "Databases" → "phpMyAdmin"
2. Import ไฟล์ SQL

## 7. วิธีการแก้ไขและอัปเดตไฟล์

### 7.1 แก้ไขไฟล์บน Local

```bash
# แก้ไขไฟล์ตามปกติใน VS Code หรือ Editor อื่นๆ
# ตัวอย่าง: แก้ไขไฟล์ pages/invoices/print.php

# ตรวจสอบการเปลี่ยนแปลง
git status

# เพิ่มไฟล์ที่แก้ไข
git add pages/invoices/print.php

# Commit การเปลี่ยนแปลง
git commit -m "Fix QR code alignment"

# พุชไปยัง GitHub
git push
```

### 7.2 อัปเดตบน AlwaysData

**วิธีที่ 1: Auto-deploy (แนะนำ)**
- AlwaysData จะดึงโค้ดจาก Git อัตโนมัติเมื่อมีการ push
- ไปที่เมนู "Git" บน AlwaysData
- คลิก "Pull" เพื่อดึงโค้ดล่าสุด

**วิธีที่ 2: Manual Pull**
```bash
# เข้า SSH ไปยัง AlwaysData
ssh username@ssh.alwaysdata.com

# เข้าไปในโฟลเดอร์ www
cd www

# ดึงโค้ดล่าสุด
git pull origin master
```

## 8. วิธีการแก้ไขไฟล์โดยตรงบน AlwaysData

**ไม่แนะนำ** แต่ถ้าจำเป็น:

```bash
# เข้า SSH ไปยัง AlwaysData
ssh username@ssh.alwaysdata.com

# แก้ไขไฟล์ด้วย nano หรือ vim
cd www
nano pages/invoices/print.php

# บันทึกการเปลี่ยนแปลง
git add pages/invoices/print.php
git commit -m "Direct edit on server"
git push
```

## 9. สรุป Workflow การทำงาน

```
1. แก้ไขไฟล์บน Local (VS Code)
2. git add .
3. git commit -m "description"
4. git push
5. ไปที่ AlwaysData → Git → Pull
```

## 10. ข้อควรระวัง

- อย่า commit ไฟล์ `config/database.php` ที่มี password จริง
- ใช้ `.gitignore` เพื่อป้องกันไฟล์ sensitive
- ทดสอบบน local ก่อน push ไป server
- Backup database ก่อนทำการอัปเดตครั้งใหญ่
