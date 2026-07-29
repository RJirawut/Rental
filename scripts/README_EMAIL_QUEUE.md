# Email Queue System

ระบบส่งอีเมลแบบ Asynchronous Queue เพื่อแก้ปัญหาระบบช้าลงเมื่อมีการส่งอีเมล

## วิธีตั้งค่า

### วิธีที่ 1: รัน Manual (สำหรับทดสอบ)
เปิด Command Prompt แล้วรัน:
```
cd C:\xampp\htdocs\Rental
php scripts\email_queue_worker.php
```

### วิธีที่ 2: ตั้งค่า Task Scheduler (แนะนำสำหรับใช้งานจริง)

1. เปิด Task Scheduler (พิมพ์ "Task Scheduler" ใน Start Menu)
2. คลิก "Create Task" ทางด้านขวา
3. ในแท็บ "General":
   - Name: Email Queue Worker
   - Security options: เลือก "Run whether user is logged on or not"
4. ในแท็บ "Triggers":
   - คลิก "New"
   - Begin the task: "On a schedule"
   - Settings: "Daily"
   - Repeat task every: 5 minutes
5. ในแท็บ "Actions":
   - คลิก "New"
   - Action: "Start a program"
   - Program/script: `C:\xampp\php\php.exe`
   - Add arguments: `C:\xampp\htdocs\Rental\scripts\email_queue_worker.php`
   - Start in: `C:\xampp\htdocs\Rental`
6. คลิก OK บันทึก

## วิธีตรวจสอบ

ตรวจสอบอีเมลใน queue ด้วย SQL:
```sql
SELECT * FROM email_queue WHERE status = 'pending';
SELECT * FROM email_queue WHERE status = 'sent' ORDER BY sent_at DESC LIMIT 10;
SELECT * FROM email_queue WHERE status = 'failed';
```

## การทำงาน

- เมื่อบันทึกบิล → อีเมลจะถูกบันทึกลงใน queue (status: pending)
- Background worker จะอ่านอีเมลจาก queue และส่งจริง
- ถ้าส่งสำเร็จ → status เปลี่ยนเป็น 'sent'
- ถ้าส่งไม่สำเร็จ → จะลองใหม่สูงสุด 3 ครั้ง แล้วเปลี่ยน status เป็น 'failed'
