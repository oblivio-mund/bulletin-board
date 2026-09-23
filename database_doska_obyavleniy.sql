-- ============================================================
-- БД: «Доска объявлений с геолокацией»
-- СУБД: PostgreSQL
-- Геоданные: PostGIS (рекомендуется для корректного
-- радиусного поиска по координатам)
--
-- Основание: ТЗ «Доска объявлений с геолокацией».
-- Минимальная модель ТЗ включает:
-- users, categories, ads, ad_images, favorites,
-- dialogs, dialog_participants, messages.
-- В физической модели дополнительно выделена cities,
-- чтобы не дублировать название города в объявлениях.
-- ============================================================

CREATE EXTENSION IF NOT EXISTS postgis;

-- Для повторного запуска скрипта в учебной среде:
DROP TABLE IF EXISTS messages CASCADE;
DROP TABLE IF EXISTS dialog_participants CASCADE;
DROP TABLE IF EXISTS dialogs CASCADE;
DROP TABLE IF EXISTS favorites CASCADE;
DROP TABLE IF EXISTS ad_images CASCADE;
DROP TABLE IF EXISTS ads CASCADE;
DROP TABLE IF EXISTS categories CASCADE;
DROP TABLE IF EXISTS cities CASCADE;
DROP TABLE IF EXISTS users CASCADE;

-- ------------------------------------------------------------
-- 1. Пользователи
-- ------------------------------------------------------------
CREATE TABLE users (
    user_id         BIGSERIAL PRIMARY KEY,
    display_name    VARCHAR(100) NOT NULL,
    login           VARCHAR(150) NOT NULL UNIQUE,
    password_hash   TEXT NOT NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_users_login ON users (login);

-- ------------------------------------------------------------
-- 2. Города / населённые пункты
-- ------------------------------------------------------------
CREATE TABLE cities (
    city_id         BIGSERIAL PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    region          VARCHAR(150),
    country         VARCHAR(100) DEFAULT 'Россия',
    latitude        NUMERIC(9,6),
    longitude       NUMERIC(9,6),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT chk_city_latitude
        CHECK (latitude IS NULL OR latitude BETWEEN -90 AND 90),
    CONSTRAINT chk_city_longitude
        CHECK (longitude IS NULL OR longitude BETWEEN -180 AND 180),
    CONSTRAINT uq_city_name_region_country
        UNIQUE (name, region, country)
);

CREATE INDEX idx_cities_name ON cities (name);

-- ------------------------------------------------------------
-- 3. Категории
-- ------------------------------------------------------------
CREATE TABLE categories (
    category_id     BIGSERIAL PRIMARY KEY,
    name            VARCHAR(100) NOT NULL UNIQUE,
    description     TEXT,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_categories_name ON categories (name);

-- ------------------------------------------------------------
-- 4. Объявления
-- ------------------------------------------------------------
CREATE TABLE ads (
    ad_id           BIGSERIAL PRIMARY KEY,
    author_id       BIGINT NOT NULL,
    category_id     BIGINT NOT NULL,
    city_id         BIGINT,
    title           VARCHAR(200) NOT NULL,
    description     TEXT NOT NULL,
    price           NUMERIC(12,2),
    status          VARCHAR(20) NOT NULL DEFAULT 'active',
    location        GEOGRAPHY(POINT, 4326),
    published_at    TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_ads_author
        FOREIGN KEY (author_id)
        REFERENCES users(user_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_ads_category
        FOREIGN KEY (category_id)
        REFERENCES categories(category_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_ads_city
        FOREIGN KEY (city_id)
        REFERENCES cities(city_id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT chk_ads_price
        CHECK (price IS NULL OR price >= 0),

    CONSTRAINT chk_ads_status
        CHECK (status IN ('draft', 'active', 'archived', 'deleted'))
);

CREATE INDEX idx_ads_author_id ON ads (author_id);
CREATE INDEX idx_ads_category_id ON ads (category_id);
CREATE INDEX idx_ads_city_id ON ads (city_id);
CREATE INDEX idx_ads_status ON ads (status);
CREATE INDEX idx_ads_published_at ON ads (published_at DESC);

-- Пространственный индекс для радиусного поиска.
CREATE INDEX idx_ads_location_gist
    ON ads USING GIST (location);

-- ------------------------------------------------------------
-- 5. Изображения объявлений
-- ------------------------------------------------------------
CREATE TABLE ad_images (
    image_id        BIGSERIAL PRIMARY KEY,
    ad_id           BIGINT NOT NULL,
    image_url       TEXT NOT NULL,
    sort_order      INTEGER NOT NULL DEFAULT 0,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_ad_images_ad
        FOREIGN KEY (ad_id)
        REFERENCES ads(ad_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT chk_ad_images_sort_order
        CHECK (sort_order >= 0)
);

CREATE INDEX idx_ad_images_ad_id ON ad_images (ad_id);
CREATE INDEX idx_ad_images_ad_sort ON ad_images (ad_id, sort_order);

-- ------------------------------------------------------------
-- 6. Избранное
-- ------------------------------------------------------------
CREATE TABLE favorites (
    user_id         BIGINT NOT NULL,
    ad_id           BIGINT NOT NULL,
    added_at        TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (user_id, ad_id),

    CONSTRAINT fk_favorites_user
        FOREIGN KEY (user_id)
        REFERENCES users(user_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_favorites_ad
        FOREIGN KEY (ad_id)
        REFERENCES ads(ad_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);

CREATE INDEX idx_favorites_ad_id ON favorites (ad_id);

-- ------------------------------------------------------------
-- 7. Диалоги
-- ------------------------------------------------------------
CREATE TABLE dialogs (
    dialog_id       BIGSERIAL PRIMARY KEY,
    ad_id           BIGINT NOT NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_dialogs_ad
        FOREIGN KEY (ad_id)
        REFERENCES ads(ad_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);

CREATE INDEX idx_dialogs_ad_id ON dialogs (ad_id);

-- ------------------------------------------------------------
-- 8. Участники диалогов
-- ------------------------------------------------------------
CREATE TABLE dialog_participants (
    dialog_id       BIGINT NOT NULL,
    user_id         BIGINT NOT NULL,
    joined_at       TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (dialog_id, user_id),

    CONSTRAINT fk_dialog_participants_dialog
        FOREIGN KEY (dialog_id)
        REFERENCES dialogs(dialog_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_dialog_participants_user
        FOREIGN KEY (user_id)
        REFERENCES users(user_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);

CREATE INDEX idx_dialog_participants_user_id
    ON dialog_participants (user_id);

-- ------------------------------------------------------------
-- 9. Сообщения
-- ------------------------------------------------------------
CREATE TABLE messages (
    message_id      BIGSERIAL PRIMARY KEY,
    dialog_id       BIGINT NOT NULL,
    sender_id       BIGINT NOT NULL,
    message_text    TEXT NOT NULL,
    sent_at         TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_messages_dialog
        FOREIGN KEY (dialog_id)
        REFERENCES dialogs(dialog_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_messages_sender
        FOREIGN KEY (sender_id)
        REFERENCES users(user_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT chk_messages_text
        CHECK (length(btrim(message_text)) > 0)
);

CREATE INDEX idx_messages_dialog_sent
    ON messages (dialog_id, sent_at);

CREATE INDEX idx_messages_sender_id
    ON messages (sender_id);

-- ------------------------------------------------------------
-- Примеры запросов для основных функций ТЗ
-- ------------------------------------------------------------

-- 1. Радиусный поиск объявлений.
-- :latitude, :longitude — координаты точки поиска.
-- :radius_meters — радиус в метрах.
--
-- SELECT
--     a.ad_id,
--     a.title,
--     a.price,
--     ST_Distance(
--         a.location,
--         ST_SetSRID(ST_MakePoint(:longitude, :latitude), 4326)::geography
--     ) AS distance_meters
-- FROM ads a
-- WHERE a.status = 'active'
--   AND a.location IS NOT NULL
--   AND ST_DWithin(
--         a.location,
--         ST_SetSRID(ST_MakePoint(:longitude, :latitude), 4326)::geography,
--         :radius_meters
--       )
-- ORDER BY distance_meters;

-- 2. Поиск объявлений по категории и тексту.
--
-- SELECT a.*
-- FROM ads a
-- WHERE a.status = 'active'
--   AND a.category_id = :category_id
--   AND (
--       a.title ILIKE '%' || :search_text || '%'
--       OR a.description ILIKE '%' || :search_text || '%'
--   )
-- ORDER BY a.published_at DESC;

-- 3. Избранные объявления пользователя.
--
-- SELECT a.*
-- FROM favorites f
-- JOIN ads a ON a.ad_id = f.ad_id
-- WHERE f.user_id = :user_id
-- ORDER BY f.added_at DESC;

-- 4. История сообщений диалога.
--
-- SELECT m.message_id, m.sender_id, m.message_text, m.sent_at
-- FROM messages m
-- WHERE m.dialog_id = :dialog_id
-- ORDER BY m.sent_at ASC;

-- 5. Получение собственных объявлений пользователя.
--
-- SELECT a.*
-- FROM ads a
-- WHERE a.author_id = :user_id
-- ORDER BY a.published_at DESC;
