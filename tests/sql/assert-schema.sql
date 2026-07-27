DELIMITER //

CREATE PROCEDURE assert_equals(
    IN assertion_name VARCHAR(128),
    IN expected_value INT,
    IN actual_value INT
)
BEGIN
    DECLARE error_message VARCHAR(255);

    IF actual_value <> expected_value THEN
        SET error_message = CONCAT(
            assertion_name,
            ': expected ',
            expected_value,
            ', got ',
            actual_value
        );
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = error_message;
    END IF;
END//

DELIMITER ;

CALL assert_equals(
    'card definitions',
    40,
    (SELECT COUNT(*) FROM twd_card_info)
);
CALL assert_equals(
    'protagonists',
    4,
    (SELECT COUNT(*) FROM twd_protagonist_info)
);
CALL assert_equals(
    'characters',
    11,
    (SELECT COUNT(*) FROM twd_character_info)
);
CALL assert_equals(
    'disaster definitions',
    7,
    (SELECT COUNT(*) FROM twd_disaster_info)
);
CALL assert_equals(
    'invalid card consequence JSON values',
    0,
    (
        SELECT COUNT(*)
        FROM twd_card_info
        WHERE (consequence_black IS NOT NULL AND JSON_VALID(consequence_black) = 0)
           OR (consequence_white IS NOT NULL AND JSON_VALID(consequence_white) = 0)
           OR (consequence_grey IS NOT NULL AND JSON_VALID(consequence_grey) = 0)
    )
);
CALL assert_equals(
    'orphan protagonist definitions',
    0,
    (
        SELECT COUNT(*)
        FROM twd_protagonist_info protagonist
        LEFT JOIN twd_card_info card ON card.info_id = protagonist.info_id
        WHERE card.info_id IS NULL
    )
);
CALL assert_equals(
    'orphan character definitions',
    0,
    (
        SELECT COUNT(*)
        FROM twd_character_info character_info
        LEFT JOIN twd_card_info card ON card.info_id = character_info.info_id
        WHERE card.info_id IS NULL
    )
);

DROP PROCEDURE assert_equals;
