-- БД «Доска объявлений с геолокацией»
-- MariaDB 10.4+ / MySQL-compatible
-- ВАЖНО: для MariaDB используется POINT без конструкции POINT SRID 4326.

CREATE DATABASE IF NOT EXISTS doska_obyavleniy
CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
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

CREATE TABLE users (
    user_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    display_name VARCHAR(100) NOT NULL,
    login VARCHAR(150) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('user','admin') NOT NULL DEFAULT 'user',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_users_login (login),
    CHECK (CHAR_LENGTH(TRIM(display_name)) >= 2),
    CHECK (CHAR_LENGTH(TRIM(login)) >= 3),
    INDEX idx_users_role (role),
    INDEX idx_users_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cities (
    city_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    region VARCHAR(150) NULL,
    country VARCHAR(100) NOT NULL DEFAULT 'Россия',
    latitude DECIMAL(9,6) NULL,
    longitude DECIMAL(9,6) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (city_id),
    UNIQUE KEY uq_city_name_region_country (name,region,country),
    CHECK (latitude IS NULL OR latitude BETWEEN -90 AND 90),
    CHECK (longitude IS NULL OR longitude BETWEEN -180 AND 180),
    INDEX idx_cities_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
    category_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (category_id),
    UNIQUE KEY uq_categories_name (name),
    CONSTRAINT fk_categories_created_by FOREIGN KEY (created_by)
        REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE SET NULL,
    CHECK (CHAR_LENGTH(TRIM(name)) >= 2),
    INDEX idx_categories_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ads (
    ad_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    author_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    city_id BIGINT UNSIGNED NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    price DECIMAL(12,2) NULL,
    status ENUM('draft','pending','active','rejected','archived','deleted')
        NOT NULL DEFAULT 'pending',
    moderation_comment VARCHAR(1000) NULL,
    moderated_by BIGINT UNSIGNED NULL,
    moderated_at TIMESTAMP NULL,
    location POINT NOT NULL,
    published_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (ad_id),
    CONSTRAINT fk_ads_author FOREIGN KEY (author_id)
        REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_ads_category FOREIGN KEY (category_id)
        REFERENCES categories(category_id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_ads_city FOREIGN KEY (city_id)
        REFERENCES cities(city_id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_ads_moderated_by FOREIGN KEY (moderated_by)
        REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE SET NULL,
    CHECK (price IS NULL OR price >= 0),
    CHECK (CHAR_LENGTH(TRIM(title)) >= 3),
    CHECK (CHAR_LENGTH(TRIM(description)) >= 10),
    CHECK (
        (status IN ('draft','pending') AND moderated_by IS NULL AND moderated_at IS NULL)
        OR
        (status IN ('active','rejected','archived','deleted')
         AND moderated_by IS NOT NULL AND moderated_at IS NOT NULL)
    ),
    INDEX idx_ads_author_id (author_id),
    INDEX idx_ads_category_id (category_id),
    INDEX idx_ads_city_id (city_id),
    INDEX idx_ads_status (status),
    INDEX idx_ads_published_at (published_at),
    INDEX idx_ads_moderated_by (moderated_by),
    SPATIAL INDEX idx_ads_location (location)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ad_images (
    image_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ad_id BIGINT UNSIGNED NOT NULL,
    image_url VARCHAR(2048) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (image_id),
    CONSTRAINT fk_ad_images_ad FOREIGN KEY (ad_id)
        REFERENCES ads(ad_id) ON UPDATE CASCADE ON DELETE CASCADE,
    CHECK (sort_order >= 0),
    INDEX idx_ad_images_ad_id (ad_id),
    INDEX idx_ad_images_ad_sort (ad_id,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE favorites (
    user_id BIGINT UNSIGNED NOT NULL,
    ad_id BIGINT UNSIGNED NOT NULL,
    added_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id,ad_id),
    CONSTRAINT fk_favorites_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_favorites_ad FOREIGN KEY (ad_id)
        REFERENCES ads(ad_id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dialogs (
    dialog_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ad_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (dialog_id),
    CONSTRAINT fk_dialogs_ad FOREIGN KEY (ad_id)
        REFERENCES ads(ad_id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dialog_participants (
    dialog_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (dialog_id,user_id),
    CONSTRAINT fk_dialog_participants_dialog FOREIGN KEY (dialog_id)
        REFERENCES dialogs(dialog_id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_dialog_participants_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE messages (
    message_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    dialog_id BIGINT UNSIGNED NOT NULL,
    sender_id BIGINT UNSIGNED NOT NULL,
    message_text TEXT NOT NULL,
    sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (message_id),
    CONSTRAINT fk_messages_dialog FOREIGN KEY (dialog_id)
        REFERENCES dialogs(dialog_id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id)
        REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CHECK (CHAR_LENGTH(TRIM(message_text)) > 0),
    INDEX idx_messages_dialog_sent (dialog_id,sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ad_moderation_history (
    moderation_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ad_id BIGINT UNSIGNED NOT NULL,
    admin_id BIGINT UNSIGNED NOT NULL,
    old_status ENUM('draft','pending','active','rejected','archived','deleted') NOT NULL,
    new_status ENUM('draft','pending','active','rejected','archived','deleted') NOT NULL,
    comment VARCHAR(1000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (moderation_id),
    CONSTRAINT fk_moderation_ad FOREIGN KEY (ad_id)
        REFERENCES ads(ad_id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_moderation_admin FOREIGN KEY (admin_id)
        REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_moderation_ad_id (ad_id),
    INDEX idx_moderation_admin_id (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Тестовые данные.
-- Пароль для учебных пользователей: password
-- В реальном приложении пароли создаются через PHP password_hash().
INSERT INTO users (display_name,login,password_hash,role,is_active) VALUES
('Иван Петров','ivan','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llCjF5sT8v1x2c0Q8Qm','user',1),
('Анна Смирнова','anna','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llCjF5sT8v1x2c0Q8Qm','user',1),
('Администратор','admin','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llCjF5sT8v1x2c0Q8Qm','admin',1);

INSERT INTO cities (name,region,country,latitude,longitude) VALUES
('Москва','Москва','Россия',55.755826,37.617300),
('Санкт-Петербург','Ленинградская область','Россия',59.934280,30.335099),
('Казань','Республика Татарстан','Россия',55.796127,49.106405);

INSERT INTO categories (name,description,created_by,is_active) VALUES
('Электроника','Компьютеры, телефоны и электроника',3,1),
('Мебель','Мебель для дома и офиса',3,1),
('Транспорт','Автомобили, мотоциклы и комплектующие',3,1),
('Недвижимость','Продажа и аренда недвижимости',3,1),
('Одежда','Одежда, обувь и аксессуары',3,1),
('Услуги','Различные услуги',3,1);

INSERT INTO ads
(author_id,category_id,city_id,title,description,price,status,location,moderated_by,moderated_at,moderation_comment)
VALUES
(1,1,1,'Ноутбук Lenovo','Продаётся ноутбук Lenovo в хорошем состоянии.',55000.00,'active',POINT(37.617300,55.755826),3,CURRENT_TIMESTAMP,'Объявление прошло модерацию.'),
(1,2,1,'Компьютерный стол','Продам компьютерный стол, состояние хорошее.',7500.00,'active',POINT(37.620000,55.752000),3,CURRENT_TIMESTAMP,'Объявление прошло модерацию.'),
(2,3,2,'Велосипед','Городской велосипед в хорошем техническом состоянии.',18000.00,'active',POINT(30.335099,59.934280),3,CURRENT_TIMESTAMP,'Объявление прошло модерацию.'),
(2,5,3,'Зимняя куртка','Зимняя куртка в хорошем состоянии, размер L.',4500.00,'pending',POINT(49.106405,55.796127),NULL,NULL,NULL);

INSERT INTO ad_images (ad_id,image_url,sort_order) VALUES
(1,'/uploads/ads/laptop.jpg',0),
(1,'/uploads/ads/laptop-2.jpg',1),
(2,'/uploads/ads/table.jpg',0),
(3,'/uploads/ads/bicycle.jpg',0),
(4,'/uploads/ads/jacket.jpg',0);

INSERT INTO favorites (user_id,ad_id) VALUES (2,1),(1,3);

INSERT INTO dialogs (ad_id) VALUES (1),(3);

INSERT INTO dialog_participants (dialog_id,user_id) VALUES
(1,1),(1,2),(2,2),(2,1);

INSERT INTO messages (dialog_id,sender_id,message_text) VALUES
(1,2,'Здравствуйте! Ноутбук ещё продаётся?'),
(1,1,'Здравствуйте! Да, объявление актуально.'),
(2,1,'Добрый день! Велосипед ещё в продаже?'),
(2,2,'Да, велосипед пока не продан.');

-- Радиусный поиск, пример для Москвы:
-- SELECT a.ad_id,a.title,a.price,
-- ST_Distance_Sphere(a.location,POINT(37.617300,55.755826)) AS distance_meters
-- FROM ads a
-- WHERE a.status='active'
-- AND a.location IS NOT NULL
-- AND ST_Distance_Sphere(a.location,POINT(37.617300,55.755826)) <= 10000
-- ORDER BY distance_meters;
