-- AI Chatbot Module Database Schema

-- Table for storing chatbot ratings
CREATE TABLE IF NOT EXISTS `PREFIX_ai_chatbot_ratings` (
  `id_rating` int(11) NOT NULL AUTO_INCREMENT,
  `id_order` int(11) NOT NULL,
  `id_customer` int(11) NOT NULL,
  `rating` tinyint(1) NOT NULL,
  `comment` text,
  `date_add` datetime NOT NULL,
  `date_upd` datetime NOT NULL,
  PRIMARY KEY (`id_rating`),
  INDEX `idx_order` (`id_order`),
  INDEX `idx_customer` (`id_customer`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Table for tracking pending ratings
CREATE TABLE IF NOT EXISTS `PREFIX_ai_chatbot_rating_pending` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_order` int(11) NOT NULL,
  `id_customer` int(11) NOT NULL,
  `rating_requested` tinyint(1) DEFAULT 0,
  `date_add` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_order` (`id_order`),
  INDEX `idx_customer` (`id_customer`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Table for tracking coupon generation and popup frequency
CREATE TABLE IF NOT EXISTS `PREFIX_ai_chatbot_coupon_tracking` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_customer` int(11) NULL DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL,
  `session_id` varchar(128) NULL DEFAULT NULL,
  `coupon_code` varchar(32) NOT NULL,
  `id_cart_rule` int(11) NOT NULL,
  `popup_shown` tinyint(1) DEFAULT 0,
  `popup_closed` tinyint(1) DEFAULT 0,
  `coupon_used` tinyint(1) DEFAULT 0,
  `date_add` datetime NOT NULL,
  `date_upd` datetime NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_customer` (`id_customer`),
  INDEX `idx_session` (`session_id`),
  INDEX `idx_ip` (`ip_address`),
  INDEX `idx_cart_rule` (`id_cart_rule`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Table for coupon popup frequency control per visitor
CREATE TABLE IF NOT EXISTS `PREFIX_ai_chatbot_popup_frequency` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_customer` int(11) NULL DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL,
  `session_id` varchar(128) NULL DEFAULT NULL,
  `last_popup_shown` datetime NOT NULL,
  `popup_count` int(11) DEFAULT 1,
  `date_add` datetime NOT NULL,
  `date_upd` datetime NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_customer_ip` (`id_customer`, `ip_address`),
  INDEX `idx_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
