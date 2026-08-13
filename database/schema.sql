-- Database Schema for Dormitory Management System
-- Create Database
CREATE DATABASE IF NOT EXISTS rental_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE rental_db;

-- Users Table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('admin') DEFAULT 'admin',
    is_active TINYINT(1) DEFAULT 1,
    account_status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    pin_failed_attempts INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_active_status (is_active, account_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Password Reset Tokens Table
CREATE TABLE password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_password_resets_user_id (user_id),
    INDEX idx_password_resets_expires_at (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Settings Table
CREATE TABLE settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dorm_name VARCHAR(100) NOT NULL DEFAULT 'My Dormitory',
    dorm_name_en VARCHAR(100) DEFAULT 'My Dormitory',
    company_name VARCHAR(200),
    address TEXT,
    address_en TEXT,
    tax_id VARCHAR(20),
    branch_number VARCHAR(5) DEFAULT '00000',
    logo VARCHAR(255),
    phone VARCHAR(20),
    email VARCHAR(100),
    water_rate DECIMAL(10,2) DEFAULT 25.00,
    electric_rate DECIMAL(10,2) DEFAULT 8.00,
    vat_rate DECIMAL(5,2) DEFAULT 7.00,
    payment_due_day INT DEFAULT 5,
    primary_color VARCHAR(7) DEFAULT '#0d6efd',
    enable_daily TINYINT(1) DEFAULT 1,
    enable_monthly TINYINT(1) DEFAULT 1,
    daily_payment_deadline_hours INT NOT NULL DEFAULT 24,
    pin VARCHAR(255) DEFAULT NULL,
    smtp_host VARCHAR(255) DEFAULT NULL,
    smtp_port INT DEFAULT 587,
    smtp_username VARCHAR(255) DEFAULT NULL,
    smtp_password VARCHAR(255) DEFAULT NULL,
    smtp_encryption VARCHAR(10) DEFAULT 'tls',
    smtp_from_email VARCHAR(255) DEFAULT NULL,
    smtp_from_name VARCHAR(255) DEFAULT NULL,
    email_enabled TINYINT(1) DEFAULT 1,
    promptpay_id VARCHAR(20) DEFAULT NULL,
    promptpay_name VARCHAR(100) DEFAULT NULL,
    bank_account_name VARCHAR(100) DEFAULT NULL,
    bank_account_number VARCHAR(30) DEFAULT NULL,
    bank_name VARCHAR(100) DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Room Types Table
CREATE TABLE room_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type_name VARCHAR(50) NOT NULL,
    type_name_en VARCHAR(50),
    price_daily DECIMAL(10,2) DEFAULT 0,
    price_monthly DECIMAL(10,2) DEFAULT 0,
    description TEXT,
    description_en TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_room_types_active_name (is_active, type_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rooms Table
CREATE TABLE rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_number VARCHAR(20) NOT NULL UNIQUE,
    room_type_id INT NOT NULL,
    floor INT DEFAULT 1,
    status ENUM('available', 'occupied', 'maintenance', 'reserved') DEFAULT 'available',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_rooms_status (status),
    INDEX idx_rooms_type_status (room_type_id, status),
    INDEX idx_rooms_floor (floor),
    FOREIGN KEY (room_type_id) REFERENCES room_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Daily Tenants Table
CREATE TABLE daily_tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id INT NOT NULL,
    guest_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    email VARCHAR(100),
    customer_tax_id VARCHAR(20),
    customer_address TEXT,
    customer_branch VARCHAR(5) DEFAULT '00000',
    check_in_date DATE NOT NULL,
    check_out_date DATE NOT NULL,
    actual_check_in_date DATE,
    actual_check_out_date DATE,
    num_guests INT DEFAULT 1,
    daily_rate DECIMAL(10,2) NOT NULL,
    total_days INT NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    other_fees DECIMAL(10,2) DEFAULT 0,
    deposit DECIMAL(10,2) DEFAULT 0,
    status ENUM('checked_in', 'checked_out', 'no_show', 'cancelled', 'pending_payment') DEFAULT NULL,
    cancel_refunded TINYINT(1) NOT NULL DEFAULT 0,
    payment_deadline DATETIME NULL,
    payment_token VARCHAR(64) DEFAULT NULL,
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_daily_room_status_dates (room_id, status, check_in_date, check_out_date),
    INDEX idx_daily_status_dates (status, check_in_date, check_out_date),
    INDEX idx_daily_created_at (created_at),
    INDEX idx_daily_updated_at (updated_at),
    INDEX idx_daily_actual_checkout (actual_check_out_date, status),
    INDEX idx_daily_email (email),
    UNIQUE KEY idx_dt_payment_token (payment_token),
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Monthly Tenants Table
CREATE TABLE monthly_tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id INT NOT NULL,
    tenant_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(100),
    customer_tax_id VARCHAR(20),
    customer_address TEXT,
    customer_branch VARCHAR(5) DEFAULT '00000',
    contract_start DATE NOT NULL,
    contract_end DATE NOT NULL,
    monthly_rent DECIMAL(10,2) NOT NULL,
    deposit DECIMAL(10,2) DEFAULT 0,
    status ENUM('active', 'expired', 'terminated', 'pending') DEFAULT 'pending',
    emergency_contact VARCHAR(100),
    emergency_phone VARCHAR(20),
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_monthly_room_status (room_id, status),
    INDEX idx_monthly_status (status),
    INDEX idx_monthly_contract_range (contract_start, contract_end),
    INDEX idx_monthly_updated_status (updated_at, status),
    INDEX idx_monthly_email (email),
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Utility Bills Table
CREATE TABLE utility_bills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    room_id INT NOT NULL,
    bill_month VARCHAR(7) NOT NULL,
    bill_date DATE NOT NULL,
    rent_amount DECIMAL(10,2) NOT NULL,
    water_prev_reading INT DEFAULT 0,
    water_curr_reading INT DEFAULT 0,
    water_units INT DEFAULT 0,
    water_rate DECIMAL(10,2) DEFAULT 25.00,
    water_amount DECIMAL(10,2) DEFAULT 0.00,
    water_old_meter_final INT DEFAULT NULL,
    water_carryover_units INT DEFAULT 0,
    elec_prev_reading INT DEFAULT 0,
    elec_curr_reading INT DEFAULT 0,
    elec_units INT DEFAULT 0,
    elec_rate DECIMAL(10,2) DEFAULT 8.00,
    elec_amount DECIMAL(10,2) DEFAULT 0.00,
    elec_old_meter_final INT DEFAULT NULL,
    elec_carryover_units INT DEFAULT 0,
    other_fees DECIMAL(10,2) DEFAULT 0.00,
    discount DECIMAL(10,2) DEFAULT 0.00,
    total_amount DECIMAL(10,2) NOT NULL,
    status ENUM('unpaid', 'paid', 'overdue') DEFAULT 'unpaid',
    paid_date DATE,
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    payment_token VARCHAR(64) DEFAULT NULL,
    INDEX idx_utility_tenant_month_id (tenant_id, bill_month, id),
    INDEX idx_utility_month_tenant_id (bill_month, tenant_id, id),
    INDEX idx_utility_status_month (status, bill_month),
    INDEX idx_utility_status_paid_date (status, paid_date),
    INDEX idx_utility_room_month (room_id, bill_month),
    INDEX idx_utility_created_at (created_at),
    UNIQUE KEY idx_ub_payment_token (payment_token),
    FOREIGN KEY (tenant_id) REFERENCES monthly_tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Invoices Table
CREATE TABLE invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(50) NOT NULL UNIQUE,
    document_type ENUM('full_tax', 'abbreviated_tax', 'receipt') DEFAULT 'full_tax',
    invoice_type ENUM('daily', 'monthly') NOT NULL,
    tenant_type ENUM('daily', 'monthly') NOT NULL,
    tenant_id INT NOT NULL,
    room_id INT NOT NULL,
    invoice_date DATE NOT NULL,
    due_date DATE,
    subtotal DECIMAL(10,2) NOT NULL,
    vat_rate DECIMAL(5,2) DEFAULT 7.00,
    vat_amount DECIMAL(10,2) DEFAULT 0,
    discount DECIMAL(10,2) DEFAULT 0,
    grand_total DECIMAL(10,2) NOT NULL,
    customer_tax_id VARCHAR(20),
    customer_branch_code VARCHAR(5) DEFAULT '00000',
    seller_branch_code VARCHAR(5) DEFAULT '00000',
    status ENUM('draft', 'issued', 'paid', 'cancelled') DEFAULT 'draft',
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_invoices_tenant_type_date_id (tenant_type, tenant_id, invoice_date, id),
    INDEX idx_invoices_invoice_type_created (invoice_type, created_at),
    INDEX idx_invoices_created_at (created_at),
    INDEX idx_invoices_room_created (room_id, created_at),
    INDEX idx_invoices_status (status),
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Invoice Items Table
CREATE TABLE invoice_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    item_description VARCHAR(255) NOT NULL,
    item_description_en VARCHAR(255),
    quantity DECIMAL(10,2) DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL,
    total_price DECIMAL(10,2) NOT NULL,
    INDEX idx_invoice_items_invoice_id (invoice_id),
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Income Statistics Table
CREATE TABLE income_statistics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    stat_type ENUM('daily', 'monthly', 'yearly') NOT NULL,
    stat_date DATE NOT NULL,
    stat_period VARCHAR(20) NOT NULL,
    daily_income DECIMAL(12,2) DEFAULT 0,
    monthly_income DECIMAL(12,2) DEFAULT 0,
    daily_guests INT DEFAULT 0,
    monthly_guests INT DEFAULT 0,
    daily_checkouts INT DEFAULT 0,
    monthly_checkouts INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_stat (stat_type, stat_period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Income Snapshots Table (preserves report totals before yearly data clearing)
CREATE TABLE income_snapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year INT NOT NULL,
    month TINYINT NULL,
    daily_income DECIMAL(12,2) DEFAULT 0,
    daily_extra_income DECIMAL(12,2) DEFAULT 0,
    monthly_income DECIMAL(12,2) DEFAULT 0,
    deposit_income DECIMAL(12,2) DEFAULT 0,
    monthly_unpaid DECIMAL(12,2) DEFAULT 0,
    held_deposit DECIMAL(12,2) DEFAULT 0,
    total_income DECIMAL(12,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_income_snapshot_month (year, month),
    INDEX idx_income_snapshots_year_month (year, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Income Daily Snapshots Table (preserves exact daily report rows before yearly data clearing)
CREATE TABLE income_daily_snapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    snapshot_date DATE NOT NULL,
    year INT NOT NULL,
    month TINYINT NOT NULL,
    day TINYINT NOT NULL,
    daily_income DECIMAL(12,2) DEFAULT 0,
    daily_extra_income DECIMAL(12,2) DEFAULT 0,
    monthly_income DECIMAL(12,2) DEFAULT 0,
    deposit_income DECIMAL(12,2) DEFAULT 0,
    monthly_unpaid DECIMAL(12,2) DEFAULT 0,
    held_deposit DECIMAL(12,2) DEFAULT 0,
    total_income DECIMAL(12,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_income_daily_snapshot_date (snapshot_date),
    INDEX idx_income_daily_snapshots_year_month_day (year, month, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Activity Log Table
CREATE TABLE activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(50) NOT NULL,
    entity_type VARCHAR(50),
    entity_id INT,
    description TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_activity_user_created (user_id, created_at),
    INDEX idx_activity_entity (entity_type, entity_id),
    INDEX idx_activity_created_at (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Email Queue Table
CREATE TABLE email_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    to_email VARCHAR(255) NOT NULL,
    to_name VARCHAR(255) DEFAULT NULL,
    subject VARCHAR(255) NOT NULL,
    html_body MEDIUMTEXT NOT NULL,
    plain_body TEXT,
    status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    attempts INT NOT NULL DEFAULT 0,
    max_attempts INT NOT NULL DEFAULT 3,
    error_message TEXT,
    sent_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email_queue_status_created (status, created_at),
    INDEX idx_email_queue_status_attempts (status, attempts, max_attempts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Payment Confirmations Table
CREATE TABLE IF NOT EXISTS payment_confirmations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bill_type ENUM('monthly','daily') NOT NULL,
    bill_id INT NOT NULL,
    room_number VARCHAR(20) DEFAULT NULL,
    tenant_name VARCHAR(100) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(50) NOT NULL DEFAULT 'promptpay',
    transfer_date DATE DEFAULT NULL,
    transfer_time TIME DEFAULT NULL,
    slip_image VARCHAR(255) DEFAULT NULL,
    status ENUM('pending_verify', 'approved', 'rejected') NOT NULL DEFAULT 'pending_verify',
    admin_note TEXT DEFAULT NULL,
    verified_by INT DEFAULT NULL,
    verified_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pc_status (status),
    INDEX idx_pc_bill (bill_type, bill_id),
    INDEX idx_pc_created (created_at),
    INDEX idx_pc_type_status_created (bill_type, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Repair Requests Table
CREATE TABLE IF NOT EXISTS repair_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_number VARCHAR(30) UNIQUE NOT NULL,
    room_id INT DEFAULT NULL,
    room_number VARCHAR(20) NOT NULL,
    reporter_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(100) DEFAULT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    priority ENUM('normal', 'urgent', 'very_urgent') DEFAULT 'normal',
    status ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
    image VARCHAR(255) DEFAULT NULL,
    admin_note TEXT DEFAULT NULL,
    resolved_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_repair_status (status),
    INDEX idx_repair_room (room_number),
    INDEX idx_repair_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Existing installations are upgraded by the compatibility migrations in functions.php.

-- Insert Default Settings
INSERT INTO settings (dorm_name, dorm_name_en, address, address_en, water_rate, electric_rate, primary_color)
VALUES ('หอพักของฉัน', 'My Dormitory', '000 ถนนสุขุมวิท แขวงคลองเตย เขตคลองเตย กรุงเทพฯ 10110', '000 Sukhumvit Road, Khlong Toei, Bangkok 10110', 5.00, 8.00, '#0d6efd');

-- Insert Default Admin User
-- Password: admin123 (hashed with PASSWORD_DEFAULT)
-- IMPORTANT: Change this password after first login!
INSERT INTO users (username, password, email, full_name, role, is_active, account_status)
VALUES ('admin', '$2y$10$iYG6pR2A6vOs4.DOsVFhzeayEKbST3TRWDoGfc3itU2zH3iMiymfa', 'admin@dormitory.com', 'Administrator', 'admin', 1, 'active');
