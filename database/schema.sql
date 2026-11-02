CREATE TABLE IF NOT EXISTS services (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    prefix CHAR(1) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tickets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service_id INT UNSIGNED NOT NULL,
    ticket_date DATE NOT NULL,
    number INT UNSIGNED NOT NULL,
    code VARCHAR(10) NOT NULL,
    status ENUM('waiting','called','done','skipped') NOT NULL DEFAULT 'waiting',
    window_no TINYINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    called_at DATETIME NULL,
    finished_at DATETIME NULL,
    UNIQUE KEY uq_ticket (service_id, ticket_date, number),
    KEY idx_status_date (ticket_date, status, id),
    CONSTRAINT fk_ticket_service FOREIGN KEY (service_id) REFERENCES services (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;