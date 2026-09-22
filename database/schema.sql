CREATE DATABASE IF NOT EXISTS tamasbau CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci;
USE tamasbau;

SET NAMES utf8mb4;

DROP TABLE IF EXISTS quote_replies;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS saved_estimates;
DROP TABLE IF EXISTS quotes;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
  phone VARCHAR(30) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(150) NOT NULL UNIQUE,
  parent_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  price INT UNSIGNED NOT NULL,
  stock INT UNSIGNED NOT NULL DEFAULT 0,
  icon VARCHAR(80) NULL,
  image_url VARCHAR(1000) NULL,
  description TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE password_resets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  email VARCHAR(190) NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_password_resets_user_id (user_id),
  INDEX idx_password_resets_expires_at (expires_at)
) ENGINE=InnoDB;

CREATE TABLE orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  total INT UNSIGNED NOT NULL,
  status VARCHAR(60) NOT NULL DEFAULT 'Feldolgozás alatt',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NULL,
  qty INT UNSIGNED NOT NULL,
  unit_price INT UNSIGNED NOT NULL,
  CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE quotes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(40) NOT NULL,
  email VARCHAR(190) NOT NULL,
  work_type VARCHAR(100) NOT NULL,
  message TEXT NOT NULL,
  status ENUM('new', 'in_progress', 'answered', 'closed') NOT NULL DEFAULT 'new',
  admin_reply TEXT NULL,
  replied_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_quotes_status_created (status, created_at),
  INDEX idx_quotes_email (email),
  INDEX idx_quotes_replied_at (replied_at)
) ENGINE=InnoDB;

CREATE TABLE quote_replies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quote_id INT UNSIGNED NOT NULL,
  admin_user_id INT UNSIGNED NOT NULL,
  reply_message TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_quote_replies_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE,
  CONSTRAINT fk_quote_replies_admin_user FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_quote_replies_quote_created (quote_id, created_at),
  INDEX idx_quote_replies_admin_user_id (admin_user_id)
) ENGINE=InnoDB;

CREATE TABLE saved_estimates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  area INT UNSIGNED NOT NULL,
  wiring_type VARCHAR(40) NOT NULL,
  alarm_qty INT UNSIGNED NOT NULL DEFAULT 0,
  camera_qty INT UNSIGNED NOT NULL DEFAULT 0,
  intercom TINYINT(1) NOT NULL DEFAULT 0,
  total INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_saved_estimates_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO users (name, email, password_hash, role, phone, is_active) VALUES
('Tamás Bau Admin', 'admin@tamasbau.hu', '$2y$10$MQrhuqpNXpFmNwG56je1XemeyoWzHhlMBOateSUfcq5zkr8mpKIl6', 'admin', '+36 (30) 123-4567', 1),
('Nagy Péter', 'nagy.peter@example.hu', '$2y$10$wyjWKleoJ76qtUVoSWxXbevwc9cjvdSYZWH5ILdSZpyFwx3ke3RuS', 'user', '+36 30 222 1111', 1),
('Szabó Éva', 'szabo.eva@example.hu', '$2y$10$QJwbtBoUexoM1F2BBmiJAu7bRePgEZ/RHTu4o4o3fsrBzW70MJUrq', 'user', '+36 30 333 2222', 1);

INSERT INTO categories (id, name, slug, parent_id) VALUES
(1, 'Riasztórendszerek', 'riasztorendszerek', NULL),
(2, 'Vezetékes riasztók', 'vezetekes-riasztok', 1),
(3, 'Vezeték nélküli riasztók', 'vezetek-nelkuli-riasztok', 1),
(4, 'Érzékelők', 'erzekelok', 1),
(5, 'Kamerák & NVR', 'kamerak-nvr', NULL),
(6, 'IP Kamerák', 'ip-kamerak', 5),
(7, 'Rögzítők', 'rogzitok', 5),
(8, 'Villanyszerelés', 'villanyszereles', NULL),
(9, 'Kismegszakítók', 'kismegszakitok', 8),
(10, 'Fi-relék', 'fi-relek', 8),
(13, 'Lakossági kismegszakítók', 'lakossagi-kismegszakitok', 9),
(14, 'Ipari kismegszakítók', 'ipari-kismegszakitok', 9),
(15, '1 fázisú Fi-relék', '1-fazisu-fi-relek', 10),
(16, '3 fázisú Fi-relék', '3-fazisu-fi-relek', 10),
(11, 'Okosotthon', 'okosotthon', NULL),
(12, 'Kaputelefonok', 'kaputelefonok', 11);

INSERT INTO products (name, category_id, price, stock, icon, image_url, description) VALUES
('Ajax Hub 2 Plus Okos Riasztóközpont', 3, 124900, 12, 'fa-shield-halved', 'https://images.unsplash.com/photo-1558002038-1055907df827?auto=format&fit=crop&w=800&q=80', 'Ethernet, Wi-Fi és dual SIM támogatású központi egység.'),
('Ajax MotionProtect Vezeték Nélküli Mozgásérzékelő', 4, 18900, 45, 'fa-sensor-on', 'https://images.unsplash.com/photo-1585776245991-cf89dd7fc73a?auto=format&fit=crop&w=800&q=80', 'Kisállat-védett infrás mozgásérzékelő.'),
('Hikvision 4K IP Dome Kamera 30m IR', 6, 42500, 18, 'fa-video', 'https://images.unsplash.com/photo-1557324232-b8917d3c3dcb?auto=format&fit=crop&w=800&q=80', 'Acusense ember/jármű megkülönböztetés, IP67 vízálló.'),
('Schneider Electric 16A Kismegszakító (C16)', 13, 1490, 150, 'fa-bolt', 'https://images.unsplash.com/photo-1581091012184-7f4f4bcbf6b1?auto=format&fit=crop&w=800&q=80', 'B és C kioldási karakterisztikával lakossági elosztókhoz.'),
('Wi-Fi Videó Kaputelefon Beltéri Egységgel', 12, 68900, 8, 'fa-door-closed', 'https://images.unsplash.com/photo-1616627455480-8c6366f75f1a?auto=format&fit=crop&w=800&q=80', 'Mobiltelefonos kapunyitás és HD videókép.'),
('Fi-Relé (Áram-védőkapcsoló) 40A 30mA', 15, 11200, 30, 'fa-plug', 'https://images.unsplash.com/photo-1584277261846-c6a1672ed979?auto=format&fit=crop&w=800&q=80', 'Életvédelmi relé családi házak védelméhez.');

INSERT INTO orders (user_id, total, status, created_at) VALUES
(2, 143800, 'Feldolgozás alatt', '2026-03-18 10:15:00'),
(3, 42500, 'Teljesítve', '2026-03-15 14:22:00');

INSERT INTO order_items (order_id, product_id, qty, unit_price) VALUES
(1, 1, 1, 124900),
(1, 2, 1, 18900),
(2, 3, 1, 42500);

INSERT INTO quotes (name, phone, email, work_type, message, status, admin_reply, replied_at, created_at) VALUES
('Varga Balázs', '+36 30 555 1234', 'varga.balazs@example.hu', 'Riasztó kiépítés', '120m2-es családi házhoz szeretnék komplett Ajax rendszert.', 'new', NULL, NULL, '2026-03-19 09:45:00'),
('Kiss Andrea', '+36 30 111 2233', 'kiss.andrea@example.hu', 'Kamerarendszer bővítés', 'Két új kültéri kamerát szeretnék a garázshoz és az udvarra.', 'answered', 'Köszönjük a megkeresést, 24 órán belül küldjük a részletes ajánlatot.', '2026-03-20 15:30:00', '2026-03-20 14:10:00');

INSERT INTO quote_replies (quote_id, admin_user_id, reply_message, created_at) VALUES
(2, 1, 'Köszönjük a megkeresést, 24 órán belül küldjük a részletes ajánlatot.', '2026-03-20 15:30:00');

INSERT INTO saved_estimates (user_id, area, wiring_type, alarm_qty, camera_qty, intercom, total, created_at) VALUES
(2, 70, 'full', 1, 2, 0, 1190000, '2026-03-20 11:30:00');
