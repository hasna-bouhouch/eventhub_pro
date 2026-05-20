-- ╔══════════════════════════════════════════════════════════════╗
-- ║  EventHub Pro — database/schema.sql  [COMPLÉTÉ - Partie 1.1]║
-- ╚══════════════════════════════════════════════════════════════╝

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS eventhub_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
USE eventhub_db;

-- ══════════════════════════════════════════════════════════════════
-- TABLE : users
-- ══════════════════════════════════════════════════════════════════
DROP TABLE IF EXISTS users;
CREATE TABLE users (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(150)  NOT NULL,
    email        VARCHAR(255)  NOT NULL UNIQUE,
    password     VARCHAR(255)  NOT NULL,
    role         ENUM('organizer','participant') NOT NULL DEFAULT 'participant',
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ══════════════════════════════════════════════════════════════════
-- TABLE : categories
-- ══════════════════════════════════════════════════════════════════
DROP TABLE IF EXISTS categories;
CREATE TABLE categories (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug          VARCHAR(50)  NOT NULL UNIQUE,
    label         VARCHAR(100) NOT NULL,
    color_primary VARCHAR(7)   NOT NULL DEFAULT '#2563EB',
    color_light   VARCHAR(7)   NOT NULL DEFAULT '#DBEAFE'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ══════════════════════════════════════════════════════════════════
-- TABLE : events
-- Correctif 1.1 : ajout de alert_sent pour éviter les doublons email
-- ══════════════════════════════════════════════════════════════════
DROP TABLE IF EXISTS events;
CREATE TABLE events (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title            VARCHAR(255)      NOT NULL,
    description      TEXT              NOT NULL,
    event_date       DATETIME          NOT NULL,
    location         VARCHAR(255)      NOT NULL,
    capacity         SMALLINT UNSIGNED NOT NULL CHECK (capacity > 0),
    category         VARCHAR(50)       NOT NULL,
    organizer_email  VARCHAR(255)      NOT NULL,
    organizer_id     INT UNSIGNED      NULL,
    -- Colonne pour éviter les doublons d'alerte 80% (Partie 2.2)
    alert_sent       TINYINT(1)        NOT NULL DEFAULT 0,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_events_organizer
        FOREIGN KEY (organizer_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ══════════════════════════════════════════════════════════════════
-- TABLE : registrations  [CRÉÉE - Partie 1.1]
-- ══════════════════════════════════════════════════════════════════
DROP TABLE IF EXISTS registrations;
CREATE TABLE registrations (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id      INT UNSIGNED  NOT NULL,
    name          VARCHAR(150)  NOT NULL,
    email         VARCHAR(255)  NOT NULL,
    -- Token unique utilisé pour le lien de désinscription
    token         VARCHAR(64)   NOT NULL UNIQUE,
    registered_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Empêche un même email de s'inscrire deux fois au même événement
    UNIQUE KEY uq_registration (event_id, email),
    CONSTRAINT fk_registrations_event
        FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ══════════════════════════════════════════════════════════════════
-- TABLE : mail_logs
-- ══════════════════════════════════════════════════════════════════
DROP TABLE IF EXISTS mail_logs;
CREATE TABLE mail_logs (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type          ENUM('confirmation','capacity_alert','ticket','other') NOT NULL,
    recipient     VARCHAR(255) NOT NULL,
    event_id      INT UNSIGNED NULL,
    error_message TEXT         NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ══════════════════════════════════════════════════════════════════
-- INDEX DE PERFORMANCE  [AJOUTÉ - Partie 1.1]
-- Justification : la fonction searchEvents() filtre souvent par date
-- ET catégorie simultanément. Un index composé (event_date, category)
-- permet au moteur MySQL d'utiliser un seul index pour les deux
-- conditions, évitant un full table scan sur les grandes tables.
-- ══════════════════════════════════════════════════════════════════
CREATE INDEX idx_events_date_category ON events (event_date, category);

SET FOREIGN_KEY_CHECKS = 1;

-- ══════════════════════════════════════════════════════════════════
-- DONNÉES DE TEST
-- ══════════════════════════════════════════════════════════════════
