# ระบบจัดการหอพัก (Dormitory Management System)

ระบบจัดการหอพักแบบครบวงจร พัฒนาด้วย PHP + MySQL + Bootstrap

## ความต้องการของระบบ

- PHP 7.4 หรือสูงกว่า
- MySQL 5.7 หรือสูงกว่า
- XAMPP/LAMP/WAMP

## การติดตั้ง

1. คัดลอกไฟล์ทั้งหมดไปยังโฟลเดอร์ `htdocs/Rental`
2. สร้างฐานข้อมูลว่างเปล่าชื่อ `rental_db` ใน phpMyAdmin
3. รันไฟล์ `database/setup.php` เพื่อสร้างตารางและข้อมูลเริ่มต้น
4. เข้าสู่ระบบด้วย:
   - Username: `admin`
   - Password: รหัสที่ `database/setup.php` แสดงหลังสร้างผู้ใช้ หรือค่าจาก `RENTAL_ADMIN_PASSWORD`
5. ลบไฟล์ `database/setup.php` หรือปล่อยให้ไฟล์ lock จาก setup บล็อกการรันซ้ำ

## ฟังก์ชันหลัก

### 1. จัดการห้องพัก
- เพิ่ม/แก้ไข/ลบ ห้องพัก
- จัดการประเภทห้องและราคา
- ค้นหาห้องด้วยหมายเลข
- แสดงสถานะห้อง (ว่าง/ไม่ว่าง/ซ่อมบำรุง)

### 2. ผู้เช่ารายวัน
- เพิ่มผู้เข้าพักพร้อมระบุวันเข้า-ออก
- ตรวจสอบวันจองไม่ให้ซ้ำกัน
- ค้นหาข้อมูลผู้เข้าพัก
- สถานะ: กำลังพัก, เช็คอิน, เช็คเอาท์, เลยกำหนด
- ออกใบกำกับภาษี

### 3. ผู้เช่ารายเดือน
- จัดการสัญญาเช่า (เริ่มต้น-สิ้นสุด)
- บันทึกค่าน้ำและค่าไฟ (มิเตอร์)
- คำนวณค่าใช้จ่ายอัตโนมัติ
- ออกใบกำกับภาษีรายเดือน

### 4. ใบกำกับภาษี
- เลขที่ใบกำกับภาษีอัตโนมัติ (INV-D-XXXX/INV-M-XXXX)
- เก็บประวัติใบกำกับภาษีย้อนหลัง
- พิมพ์ใบกำกับภาษีได้

### 5. รายงานและสถิติ
- Dashboard แสดงสถิติสรุป
- กราฟรายได้รายวัน/รายเดือน
- กราฟจำนวนผู้เข้าพัก
- Export ข้อมูลเป็น CSV

### 6. การตั้งค่า
- ตั้งชื่อหอพักและที่อยู่
- อัพโหลดโลโก้
- ตั้งค่าเลขประจำตัวผู้เสียภาษี
- ปรับเรทค่าน้ำค่าไฟ

### 7. ระบบผู้ใช้งาน
- ล็อกอินด้วย Username/Password
- ลืมรหัสผ่าน (รีเซ็ตผ่านหน้าเว็บ)
- แยกสิทธิ์ Admin และ User
- บังคับมีผู้ใช้งานอย่างน้อย 1 คน

### 8. รองรับ 2 ภาษา
- ภาษาไทย
- ภาษาอังกฤษ
- สลับภาษาได้ทุกหน้า

## โครงสร้างไฟล์

```
Rental/
├── index.php                      # Redirect ไปหน้า login
├── config/
│   └── database.php               # ตั้งค่าฐานข้อมูล
├── includes/
│   ├── header.php                 # Header ส่วนกลาง
│   ├── footer.php                 # Footer ส่วนกลาง
│   └── functions.php              # ฟังก์ชันช่วยเหลือ
├── assets/
│   ├── css/style.css              # สไตล์ชีต
│   └── lang/language.php          # ไฟล์ภาษา
├── pages/
│   ├── dashboard.php              # หน้าแดชบอร์ด
│   ├── settings.php               # หน้าตั้งค่า
│   ├── auth/
│   │   └── logout.php             # ออกจากระบบ
│   ├── admin/
│   │   ├── users.php              # จัดการผู้ใช้
│   │   └── user-form.php          # เพิ่ม/แก้ไขผู้ใช้
│   ├── rooms/
│   │   ├── index.php              # รายการห้อง
│   │   └── form.php               # เพิ่ม/แก้ไขห้อง
│   ├── room-types/
│   │   ├── index.php              # ประเภทห้อง
│   │   └── form.php               # เพิ่ม/แก้ไขประเภท
│   ├── daily-tenants/
│   │   ├── index.php              # ผู้เข้าพักรายวัน
│   │   ├── form.php               # เพิ่ม/แก้ไขผู้เข้าพัก
│   │   ├── invoice.php            # ใบกำกับภาษี
│   │   └── print.php              # พิมพ์ใบกำกับภาษี
│   ├── monthly-tenants/
│   │   ├── index.php              # ผู้เช่ารายเดือน
│   │   ├── form.php               # เพิ่ม/แก้ไขผู้เช่า
│   │   ├── invoice.php            # ใบกำกับภาษี
│   │   └── print.php              # พิมพ์ใบกำกับภาษี
│   ├── utility-bills/
│   │   ├── index.php              # บิลค่าน้ำค่าไฟ
│   │   └── form.php               # บันทึกมิเตอร์
│   ├── invoices/
│   │   ├── index.php              # ประวัติใบกำกับภาษี
│   │   ├── view.php               # ดูรายละเอียด
│   │   └── print.php              # พิมพ์
│   └── reports/
│       ├── income.php             # รายงานรายได้
│       └── occupancy.php          # รายงานจำนวนผู้เข้าพัก
├── api/
│   └── index.php                  # API endpoints
└── database/
    ├── schema.sql                 # สคีมาฐานข้อมูล
    ├── seed.sql                   # ข้อมูลตัวอย่าง
    └── setup.php                  # ติดตั้งระบบ
```

## ข้อมูลเข้าสู่ระบบเริ่มต้น

- **Username:** admin
- **Password:** รหัสที่ setup แสดงหลังติดตั้ง หรือค่าจาก `RENTAL_ADMIN_PASSWORD`

## ความปลอดภัย

- รหัสผ่านเข้ารหัสด้วย bcrypt
- ตรวจสอบ session ทุกหน้า
- ป้องกัน SQL Injection ด้วย prepared statements
- ป้องกัน XSS ด้วย htmlspecialchars
- ตั้งค่า production ผ่าน environment variables ได้: `RENTAL_DB_HOST`, `RENTAL_DB_USER`, `RENTAL_DB_PASSWORD`, `RENTAL_DB_NAME`, `RENTAL_BASE_URL`, `RENTAL_APP_HOST`, `RENTAL_APP_SCHEME`
- หลัง deploy ฐานข้อมูลเดิม ให้รัน `database/performance_indexes.sql` เพื่อเพิ่ม index สำหรับข้อมูลจำนวนมาก

## การสนับสนุน

หากมีปัญหาหรือคำถาม กรุณาติดต่อผู้พัฒนา

---

พัฒนาด้วย ❤️ สำหรับระบบจัดการหอพัก
"# Rental" 
"# TestRental" 
"# TestRental" 
