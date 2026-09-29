-- БД «Доска объявлений с геолокацией»
-- MySQL 8.0+
CREATE DATABASE IF NOT EXISTS doska_obyavleniy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE doska_obyavleniy;

SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS ad_moderation_history;
DROP TABLE IF EXISTS messages;
DROP TABLE IF EXISTS dialog_participants;
DROP TABLE IF EXISTS dialogs;
DROP TABLE IF EXISTS favorites;
DROP TABLE IF EXISTS ad_images;
DROP TABLE IF EXISTS ads;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS cities;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS=1;

-- Роли: guest не хранится в users, поскольку гость не имеет учётной записи.
-- Для зарегистрированных пользователей используются user/admin.
CREATE TABLE users (
    user_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    display_name VARCHAR(100) NOT NULL,
    login VARCHAR(150) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('user','admin') NOT NULL DEFAULT 'user',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_users_login (login),
    CONSTRAINT chk_users_display_name CHECK (CHAR_LENGTH(TRIM(display_name)) >= 2),
    CONSTRAINT chk_users_login CHECK (CHAR_LENGTH(TRIM(login)) >= 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cities (
    city_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    region VARCHAR(150),
    country VARCHAR(100) NOT NULL DEFAULT 'Россия',
    latitude DECIMAL(9,6),
    longitude DECIMAL(9,6),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (city_id),
    UNIQUE KEY uq_city_name_region_country (name, region, country),
    CONSTRAINT chk_city_latitude CHECK (latitude IS NULL OR latitude BETWEEN -90 AND 90),
    CONSTRAINT chk_city_longitude CHECK (longitude IS NULL OR longitude BETWEEN -180 AND 180),
    INDEX idx_cities_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
    category_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (category_id),
    UNIQUE KEY uq_categories_name (name),
    CONSTRAINT fk_categories_created_by FOREIGN KEY (created_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_categories_active (is_active),
    INDEX idx_categories_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ads (
    ad_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    author_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    city_id BIGINT UNSIGNED NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    price DECIMAL(12,2),
    status ENUM('draft','pending','active','rejected','archived','deleted') NOT NULL DEFAULT 'pending',
    moderation_comment VARCHAR(1000),
    moderated_by BIGINT UNSIGNED NULL,
    moderated_at TIMESTAMP NULL,
    location POINT SRID 4326 NULL,
    published_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (ad_id),
    CONSTRAINT fk_ads_author FOREIGN KEY (author_id) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_ads_category FOREIGN KEY (category_id) REFERENCES categories(category_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_ads_city FOREIGN KEY (city_id) REFERENCES cities(city_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_ads_moderated_by FOREIGN KEY (moderated_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_ads_price CHECK (price IS NULL OR price >= 0),
    CONSTRAINT chk_ads_title CHECK (CHAR_LENGTH(TRIM(title)) >= 3),
    CONSTRAINT chk_ads_description CHECK (CHAR_LENGTH(TRIM(description)) >= 10),
    CONSTRAINT chk_ads_moderation_data CHECK (
        (status IN ('draft','pending') AND moderated_by IS NULL)
        OR (status IN ('active','rejected','archived','deleted') AND moderated_by IS NOT NULL AND moderated_at IS NOT NULL)
    ),
    INDEX idx_ads_author_id (author_id),
    INDEX idx_ads_category_id (category_id),
    INDEX idx_ads_city_id (city_id),
    INDEX idx_ads_status (status),
    INDEX idx_ads_published_at (published_at),
    INDEX idx_ads_moderated_by (moderated_by),
    SPATIAL INDEX idx_ads_location (location)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Журнал модерации объявлений.
CREATE TABLE ad_moderation_history (
    moderation_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ad_id BIGINT UNSIGNED NOT NULL,
    admin_id BIGINT UNSIGNED NOT NULL,
    old_status ENUM('draft','pending','active','rejected','archived','deleted') NULL,
    new_status ENUM('draft','pending','active','rejected','archived','deleted') NOT NULL,
    moderation_comment VARCHAR(1000),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (moderation_id),
    CONSTRAINT fk_moderation_ad FOREIGN KEY (ad_id) REFERENCES ads(ad_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_moderation_admin FOREIGN KEY (admin_id) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_moderation_ad (ad_id, created_at),
    INDEX idx_moderation_admin (admin_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ad_images (
    image_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ad_id BIGINT UNSIGNED NOT NULL,
    image_url VARCHAR(2048) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (image_id),
    CONSTRAINT fk_ad_images_ad FOREIGN KEY (ad_id) REFERENCES ads(ad_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_ad_images_sort_order CHECK (sort_order >= 0),
    INDEX idx_ad_images_ad_id (ad_id),
    INDEX idx_ad_images_ad_sort (ad_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE favorites (
    user_id BIGINT UNSIGNED NOT NULL,
    ad_id BIGINT UNSIGNED NOT NULL,
    added_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, ad_id),
    CONSTRAINT fk_favorites_user FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_favorites_ad FOREIGN KEY (ad_id) REFERENCES ads(ad_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_favorites_ad_id (ad_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dialogs (
    dialog_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ad_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (dialog_id),
    CONSTRAINT fk_dialogs_ad FOREIGN KEY (ad_id) REFERENCES ads(ad_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_dialogs_ad_id (ad_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dialog_participants (
    dialog_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (dialog_id, user_id),
    CONSTRAINT fk_dialog_participants_dialog FOREIGN KEY (dialog_id) REFERENCES dialogs(dialog_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_dialog_participants_user FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_dialog_participants_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE messages (
    message_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    dialog_id BIGINT UNSIGNED NOT NULL,
    sender_id BIGINT UNSIGNED NOT NULL,
    message_text TEXT NOT NULL,
    sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (message_id),
    CONSTRAINT fk_messages_dialog FOREIGN KEY (dialog_id) REFERENCES dialogs(dialog_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_messages_text CHECK (CHAR_LENGTH(TRIM(message_text)) > 0),
    INDEX idx_messages_dialog_sent (dialog_id, sent_at),
    INDEX idx_messages_sender_id (sender_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Контроль бизнес-правил на уровне БД
-- ============================================================

DELIMITER $$

-- Только администратор может указывать created_by для категории.
CREATE TRIGGER trg_categories_created_by_admin
BEFORE INSERT ON categories
FOR EACH ROW
BEGIN
    IF NEW.created_by IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM users u WHERE u.user_id = NEW.created_by AND u.role = 'admin' AND u.is_active = TRUE
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'created_by должен ссылаться на активного администратора';
    END IF;
END$$

CREATE TRIGGER trg_categories_created_by_admin_update
BEFORE UPDATE ON categories
FOR EACH ROW
BEGIN
    IF NEW.created_by IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM users u WHERE u.user_id = NEW.created_by AND u.role = 'admin' AND u.is_active = TRUE
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'created_by должен ссылаться на активного администратора';
    END IF;
END$$

-- Модерация/изменение статусов доступна только активному администратору.
CREATE TRIGGER trg_ads_moderation_admin
BEFORE UPDATE ON ads
FOR EACH ROW
BEGIN
    IF NEW.status <> OLD.status OR NOT (NEW.moderated_by <=> OLD.moderated_by) THEN
        IF NEW.status IN ('active','rejected','archived','deleted') THEN
            IF NEW.moderated_by IS NULL OR NOT EXISTS (
                SELECT 1 FROM users u
                WHERE u.user_id = NEW.moderated_by
                  AND u.role = 'admin'
                  AND u.is_active = TRUE
            ) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Изменять статус объявления может только активный администратор';
            END IF;
            IF NEW.moderated_at IS NULL THEN
                SET NEW.moderated_at = CURRENT_TIMESTAMP;
            END IF;
        END IF;
    END IF;
END$$

-- Автоматическое ведение журнала модерации.
CREATE TRIGGER trg_ads_moderation_history
AFTER UPDATE ON ads
FOR EACH ROW
BEGIN
    IF NEW.status <> OLD.status
       AND NEW.status IN ('active','rejected','archived','deleted')
       AND NEW.moderated_by IS NOT NULL THEN
        INSERT INTO ad_moderation_history
            (ad_id, admin_id, old_status, new_status, moderation_comment)
        VALUES
            (NEW.ad_id, NEW.moderated_by, OLD.status, NEW.status, NEW.moderation_comment);
    END IF;
END$$

DELIMITER ;

-- ============================================================
-- ТЕСТОВЫЕ ДАННЫЕ
-- ============================================================
INSERT INTO users (display_name, login, password_hash, role) VALUES
('Иван Петров', 'ivan.petrov', '$2y$10$demo_hash_ivan', 'user'),
('Анна Смирнова', 'anna.smirnova', '$2y$10$demo_hash_anna', 'user'),
('Администратор', 'admin', '$2y$10$demo_hash_admin', 'admin');

INSERT INTO cities (name, region, country, latitude, longitude) VALUES
('Москва', 'Москва', 'Россия', 55.755826, 37.617300),
('Санкт-Петербург', 'Ленинградская область', 'Россия', 59.934280, 30.335099),
('Казань', 'Республика Татарстан', 'Россия', 55.796127, 49.106414);

INSERT INTO categories (name, description, is_active, created_by) VALUES
('Электроника', 'Компьютеры, телефоны и другая электроника', TRUE, 3),
('Автомобили', 'Легковые и коммерческие автомобили', TRUE, 3),
('Недвижимость', 'Продажа и аренда недвижимости', TRUE, 3),
('Одежда', 'Одежда и обувь', TRUE, 3),
('Услуги', 'Различные услуги', TRUE, 3),
('Хобби', 'Товары для спорта, творчества и увлечений', TRUE, 3);

INSERT INTO ads
(author_id, category_id, city_id, title, description, price, status, moderated_by, moderated_at, published_at, location)
VALUES
(1, 1, 1, 'Ноутбук Lenovo', 'Ноутбук Lenovo в хорошем состоянии, подходит для учёбы и работы.', 45000.00,
 'active', 3, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ST_SRID(POINT(37.6173,55.755826),4326)),
(2, 6, 1, 'Горный велосипед', 'Горный велосипед для прогулок, технически исправен.', 28000.00,
 'active', 3, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ST_SRID(POINT(37.6200,55.7520),4326)),
(1, 5, 2, 'Ремонт компьютеров', 'Настройка, диагностика и ремонт персональных компьютеров.', 1500.00,
 'active', 3, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ST_SRID(POINT(30.3351,59.9343),4326)),
(2, 4, 3, 'Зимняя куртка', 'Тёплая зимняя куртка в хорошем состоянии.', 5000.00,
 'pending', NULL, NULL, NULL, ST_SRID(POINT(49.1064,55.7961),4326));

INSERT INTO ad_images (ad_id, image_url, sort_order) VALUES
(1, 'https://example.com/images/laptop.jpg', 0),
(2, 'https://example.com/images/bicycle.jpg', 0),
(3, 'https://example.com/images/repair.jpg', 0);

INSERT INTO favorites (user_id, ad_id) VALUES
(2, 1),
(1, 2);

INSERT INTO dialogs (ad_id) VALUES
(1),
(2);

INSERT INTO dialog_participants (dialog_id, user_id) VALUES
(1, 1), (1, 2),
(2, 1), (2, 2);

INSERT INTO messages (dialog_id, sender_id, message_text) VALUES
(1, 2, 'Здравствуйте! Ноутбук ещё продаётся?'),
(1, 1, 'Здравствуйте! Да, объявление актуально.'),
(2, 1, 'Добрый день! Можно посмотреть велосипед?'),
(2, 2, 'Да, конечно.');

-- ============================================================
-- ПРИМЕРЫ ОСНОВНЫХ ЗАПРОСОВ
-- ============================================================

-- Радиусный поиск, :latitude/:longitude — центр, :radius_meters — радиус.
-- SELECT a.ad_id, a.title, a.price,
--        ST_Distance_Sphere(a.location,
--             ST_SRID(POINT(:longitude, :latitude),4326)) AS distance_meters
-- FROM ads a
-- WHERE a.status='active' AND a.location IS NOT NULL
--   AND ST_Distance_Sphere(a.location,
--       ST_SRID(POINT(:longitude, :latitude),4326)) <= :radius_meters
-- ORDER BY distance_meters;

-- Модерация администратором:
-- UPDATE ads
-- SET status='active', moderated_by=:admin_id,
--     moderated_at=CURRENT_TIMESTAMP,
--     published_at=COALESCE(published_at,CURRENT_TIMESTAMP)
-- WHERE ad_id=:ad_id AND status='pending';

-- Отклонение объявления:
-- UPDATE ads
-- SET status='rejected', moderated_by=:admin_id,
--     moderated_at=CURRENT_TIMESTAMP,
--     moderation_comment=:comment
-- WHERE ad_id=:ad_id AND status='pending';

-- Журнал модерации:
-- SELECT h.*, u.display_name AS admin_name
-- FROM ad_moderation_history h
-- JOIN users u ON u.user_id=h.admin_id
-- WHERE h.ad_id=:ad_id
-- ORDER BY h.created_at DESC;

-- Управление пользователем администратором:
-- UPDATE users SET is_active=FALSE WHERE user_id=:user_id;

-- Добавление объявления в избранное:
-- INSERT INTO favorites(user_id,ad_id) VALUES(:user_id,:ad_id);

-- Избранные объявления:
-- SELECT a.* FROM favorites f JOIN ads a ON a.ad_id=f.ad_id
-- WHERE f.user_id=:user_id ORDER BY f.added_at DESC;

-- Сообщения диалога:
-- SELECT m.* FROM messages m WHERE m.dialog_id=:dialog_id ORDER BY m.sent_at;
