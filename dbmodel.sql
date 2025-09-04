-- ------
-- BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
-- TheWalkingDeck implementation : © <Cedor> <cedordev@gmail.com>
-- 
-- This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
-- See http://en.boardgamearena.com/#!doc/Studio for more information.
-- -----
-- dbmodel.sql
-- This is the file where you are describing the database schema of your game
-- Basically, you just have to export from PhpMyAdmin your table structure and copy/paste
-- this export here.
-- Note that the database itself and the standard tables ("global", "stats", "gamelog" and "player") are
-- already created and must not be created here
-- Note: The database schema is created from this file when the game starts. If you modify this file,
--       you have to restart a game to see your changes in database.
CREATE TABLE IF NOT EXISTS `card` (
  `card_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `card_type` varchar(16) NOT NULL,
  `card_type_arg` int(11) NOT NULL,
  `card_location` varchar(16) NOT NULL,
  `card_location_arg` int(11) NOT NULL,
  PRIMARY KEY (`card_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8 AUTO_INCREMENT = 1;

CREATE TABLE IF NOT EXISTS `card_info` (
  `info_id` int(10) unsigned NOT NULL,
  `card_type` varchar(16) NOT NULL,
  `card_type_arg` int(11) NOT NULL,
  `card_name` varchar(64) NOT NULL,
  `is_zombie` TINYINT(1) NOT NULL DEFAULT 0,
  `is_character` TINYINT(1) NOT NULL DEFAULT 0,
  `consequence_black` JSON,
  `consequence_white` JSON,
  `consequence_grey` JSON,
  `special_draw` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`info_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8 AUTO_INCREMENT = 1;

CREATE TABLE IF NOT EXISTS `protagonist_info` (
  `info_id` int(10) unsigned NOT NULL,
  `losscon` TINYINT(1) NOT NULL DEFAULT 5,
  FOREIGN KEY (info_id) REFERENCES `card_info` (`info_id`) 
) ENGINE = InnoDB DEFAULT CHARSET = utf8 AUTO_INCREMENT = 1;

CREATE TABLE IF NOT EXISTS `character_info` (
  `info_id` int(10) unsigned NOT NULL,
  `weakness_1` TINYINT(1) NOT NULL DEFAULT 0,
  `weakness_2` TINYINT(1) NOT NULL DEFAULT 0,
  `weakness_3` TINYINT(1) NOT NULL DEFAULT 0,
  `wounds` TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (info_id) REFERENCES `card_info` (`info_id`) 
) ENGINE = InnoDB DEFAULT CHARSET = utf8 AUTO_INCREMENT = 1;

CREATE TABLE IF NOT EXISTS `disaster` (
  `card_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `card_type` varchar(16) NOT NULL,
  `card_type_arg` int(11) NOT NULL,
  `card_location` varchar(16) NOT NULL,
  `card_location_arg` int(11) NOT NULL,
  PRIMARY KEY (`card_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8 AUTO_INCREMENT = 1;

CREATE TABLE IF NOT EXISTS `disaster_info` (
  `card_type` varchar(16) NOT NULL,
  `disaster1` TINYINT(1) NOT NULL DEFAULT 0,
  `disaster2` TINYINT(1) NOT NULL DEFAULT 0,
  `disaster3` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`card_type`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8 AUTO_INCREMENT = 1;

-- Create all the cards info
INSERT INTO `card_info` (`info_id`, `card_type`, `card_type_arg`, `card_name`, `is_zombie`, `is_character`, `consequence_black`, `consequence_white`, `consequence_grey`, `special_draw`) VALUES
(1, '1', 1, 'Aenor', 0, 0, NULL, NULL, NULL, 0),
(2, '1', 2, 'Boris\r\n', 0, 0, NULL, NULL, NULL, 0),
(3, '1', 3, 'Adrien', 0, 0, NULL, NULL, NULL, 0),
(4, '1', 4, 'Eleonore', 0, 0, NULL, NULL, NULL, 0),
(5, '2', 1, 'Punk', 1, 0, '{"action" : "bury", "bury" : "this"}', NULL, NULL, 0),
(6, '2', 2, 'Wolf Trap', 0, 0, '{"action" : "other"}', NULL, '{"action" : "other"}', 0),
(7, '2', 3, 'Clown', 1, 0, NULL, NULL, '{"action" : "bury", "bury" : "this"}', 0),
(8, '2', 4, 'Ellie and Joel', 0, 1, NULL, NULL, '{"action" : "other"}', 0),
(9, '2', 5, 'Kieren', 0, 1, '{"action" : "multiple", "number" : 2, "0" : {"action" : "other"}, "1" : {"action" : "bury", "bury" : "this"}}', NULL, '{"action" : "other"}', 0),
(10, '2', 6, 'Tallahassee', 0, 1, NULL, '{"action" : "other"}', '{"action" : "other"}', 0),
(11, '2', 7, 'Gretchen', 0, 1, '{"action" : "multiple", "number" : 2, "0" : {"action" : "other"}, "1" : {"action" : "bury", "bury" : "this"}}', NULL, '{"action" : "other"}', 0),
(12, '2', 8, 'Robert', 0, 1, NULL, NULL, '{"action" : "other"}', 0),
(13, '2', 9, 'Brigade', 1, 0, NULL, '{"action" : "other"}', '{"action" : "other"}', 1),
(14, '2', 10, 'Bonfire', 0, 0, '{"action" : "draw", "number" : 3}', NULL, '{"action" : "other"}', 0),
(15, '2', 11, 'Horse', 0, 0, '{"action" : "draw", "number" : 2}', NULL, '{"action" : "other"}', 0),
(16, '2', 12, 'RV', 0, 0, '{"action" : "draw", "number" : 2}', NULL, '{"action" : "restore", "ressource" : "ressource2"}', 0),
(17, '2', 13, 'Cellar', 0, 0, '{"action" : "other"}', NULL, '{"action" : "other"}', 0),
(18, '2', 14, 'Teddy Bear', 0, 0, '{"action" : "restore", "ressource" : "ressource3"}', NULL, '{"action" : "other"}', 0),
(19, '2', 15, 'Wild Zero', 1, 0, '{"action" : "other"}', NULL, '{"action" : "other"}', 0),
(20, '2', 16, 'Voodoo', 1, 0, '{"action" : "other"}', NULL, '{"action" : "other"}', 0),
(21, '2', 17, 'Mutt', 0, 0, '{"action" : "other"}', NULL, '{"action" : "other"}', 0),
(22, '2', 18, 'Grenade', 0, 0, '{"action" : "other"}', NULL, '{"action" : "other"}', 0),
(23, '3', 1, 'Musicians', 1, 0, NULL, NULL, '{"action" : "other"}', 0),
(24, '3', 2, 'Site Manager', 1, 0, NULL, '{"action" : "draw", "number" : 1}', '{"action" : "other"}', 0),
(25, '3', 3, 'Glenn', 0, 1, NULL, '{"action" : "other"}', '{"action" : "other"}', 0),
(26, '3', 4, 'Murphy', 0, 1, '{"action" : "bury", "bury" : "this"}', NULL, '{"action" : "other"}', 0),
(27, '3', 5, 'Horde', 1, 0, NULL, '{"action" : "other"}', '{"action" : "other"}', 1),
(28, '3', 6, 'Butler', 1, 0, NULL, '{"action" : "consume", "ressource" : "ressource2"}', '{"action" : "other"}', 0),
(29, '3', 7, 'Canned food', 0, 0, '{"action" : "nothing"}', '{"action" : "consume", "ressource" : "ressource1"}', '{"action" : "other"}', 0),
(30, '3', 8, 'Warehouse', 0, 0, '{"action" : "other"}', NULL, '{"action" : "other"}', 0),
(31, '3', 9, 'Medical alcohol', 0, 0, '{"action" : "other"}', NULL, '{"action" : "other"}', 0),
(32, '3', 10, 'Map', 0, 0, '{"action" : "other"}', NULL, '{"action" : "other"}', 0),
(33, '3', 11, 'Domitille', 0, 1, '{"action" : "other"}', NULL, '{"action" : "other"}', 0),
(34, '3', 12, 'The reaper', 1, 0, '{"action" : "bury", "bury" : "character"}', NULL, '{"action" : "other"}', 0),
(35, '3', 13, 'Controller', 1, 0, '{"action" : "other"}', NULL, '{"action" : "other"}', 0),
(36, '3', 14, 'Zoey', 0, 1, '{"action" : "other"}', NULL, '{"action" : "other"}', 0),
(37, '3', 15, 'Jill', 0, 1, '{"action" : "multiple", "number" : 2, "0" : {"action" : "other"}, "1" : {"action" : "bury", "bury" : "this"}}', NULL, '{"action" : "other"}', 0),
(38, '3', 16, 'Shaun', 0, 1, '[{"action" : "bury", "bury" : "this"}, {"action" : "draw", "number" : 2}]', '{"action" : "consume", "ressource" : "ressource3"}', '{"action" : "nothing"}', 0),
(39, '3', 17, 'LGS', 0, 0, '{"action" : "other"}', NULL, '{"action" : "restore", "ressource" : "ressource1"}', 0),
(40, '3', 18, 'Teacher', 1, 0, '{"action" : "bury", "bury" : "topCard"}', NULL, NULL, 0)
;

-- Create Protagonist info
INSERT INTO `protagonist_info` (`info_id`, `losscon`) VALUES
(1, 5),
(2, 5),
(3, 4),
(4, 3)
;

-- Create characters info
INSERT INTO `character_info` (`info_id`, `weakness_1`, `weakness_2`, `weakness_3`, `wounds`) VALUES
(8, 1, 0, 1, 0),
(9, 0, 0, 1, 0),
(10, 1, 0, 1, 0),
(11, 0, 0, 1, 0),
(12, 0, 1, 0, 0),
(25, 0, 1, 0, 0),
(26, 0, 0, 1, 0),
(33, 1, 0, 0, 0),
(36, 1, 0, 0, 0),
(37, 0, 0, 1, 0),
(38, 0, 0, 1, 0)
;

-- Create disaster info
INSERT INTO `disaster_info` (`card_type`, `disaster1`, `disaster2`, `disaster3`) VALUES
(1, 1, 0, 0),
(2, 0, 1, 0),
(3, 0, 0, 1),
(4, 1, 1, 0),
(5, 0, 1, 1),
(6, 1, 0, 1),
(7, 0, 0, 0)
;