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
    'card texts column',
    1,
    (
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'twd_card_info'
          AND column_name = 'texts'
          AND is_nullable = 'YES'
          AND column_default IS NULL
    )
);
CALL assert_equals(
    'invalid card texts JSON values',
    0,
    (
        SELECT COUNT(*)
        FROM twd_card_info
        WHERE texts IS NOT NULL
          AND JSON_VALID(texts) = 0
    )
);
CALL assert_equals(
    'invalid card texts root values',
    0,
    (
        SELECT COUNT(*)
        FROM twd_card_info
        WHERE texts IS NOT NULL
          AND JSON_TYPE(texts) <> 'OBJECT'
    )
);
CALL assert_equals(
    'unknown card text zones',
    0,
    (
        SELECT COUNT(*)
        FROM twd_card_info
        WHERE texts IS NOT NULL
          AND JSON_LENGTH(texts) <>
              JSON_CONTAINS_PATH(texts, 'one', '$.black')
              + JSON_CONTAINS_PATH(texts, 'one', '$.white')
              + JSON_CONTAINS_PATH(texts, 'one', '$.grey')
              + JSON_CONTAINS_PATH(texts, 'one', '$.special')
    )
);
CALL assert_equals(
    'invalid card text zones',
    0,
    (
        SELECT COUNT(*)
        FROM twd_card_info
        WHERE texts IS NOT NULL
          AND (
              (JSON_CONTAINS_PATH(texts, 'one', '$.black') = 1
                  AND JSON_TYPE(JSON_EXTRACT(texts, '$.black')) <> 'OBJECT')
              OR (JSON_CONTAINS_PATH(texts, 'one', '$.white') = 1
                  AND JSON_TYPE(JSON_EXTRACT(texts, '$.white')) <> 'OBJECT')
              OR (JSON_CONTAINS_PATH(texts, 'one', '$.grey') = 1
                  AND JSON_TYPE(JSON_EXTRACT(texts, '$.grey')) <> 'OBJECT')
              OR (JSON_CONTAINS_PATH(texts, 'one', '$.special') = 1
                  AND JSON_TYPE(JSON_EXTRACT(texts, '$.special')) <> 'OBJECT')
          )
    )
);
CALL assert_equals(
    'unknown card text zone properties',
    0,
    (
        SELECT COUNT(*)
        FROM twd_card_info
        WHERE texts IS NOT NULL
          AND (
              (JSON_CONTAINS_PATH(texts, 'one', '$.black') = 1
                  AND JSON_LENGTH(JSON_EXTRACT(texts, '$.black')) <>
                      JSON_CONTAINS_PATH(texts, 'one', '$.black.text')
                      + JSON_CONTAINS_PATH(texts, 'one', '$.black.args'))
              OR (JSON_CONTAINS_PATH(texts, 'one', '$.white') = 1
                  AND JSON_LENGTH(JSON_EXTRACT(texts, '$.white')) <>
                      JSON_CONTAINS_PATH(texts, 'one', '$.white.text')
                      + JSON_CONTAINS_PATH(texts, 'one', '$.white.args'))
              OR (JSON_CONTAINS_PATH(texts, 'one', '$.grey') = 1
                  AND JSON_LENGTH(JSON_EXTRACT(texts, '$.grey')) <>
                      JSON_CONTAINS_PATH(texts, 'one', '$.grey.text')
                      + JSON_CONTAINS_PATH(texts, 'one', '$.grey.args'))
              OR (JSON_CONTAINS_PATH(texts, 'one', '$.special') = 1
                  AND JSON_LENGTH(JSON_EXTRACT(texts, '$.special')) <>
                      JSON_CONTAINS_PATH(texts, 'one', '$.special.text')
                      + JSON_CONTAINS_PATH(texts, 'one', '$.special.args'))
          )
    )
);
CALL assert_equals(
    'invalid card text property types',
    0,
    (
        SELECT COUNT(*)
        FROM twd_card_info
        WHERE texts IS NOT NULL
          AND (
              (JSON_CONTAINS_PATH(texts, 'one', '$.black.text') = 1
                  AND JSON_TYPE(JSON_EXTRACT(texts, '$.black.text')) <> 'STRING')
              OR (JSON_CONTAINS_PATH(texts, 'one', '$.black.args') = 1
                  AND JSON_TYPE(JSON_EXTRACT(texts, '$.black.args')) <> 'OBJECT')
              OR (JSON_CONTAINS_PATH(texts, 'one', '$.white.text') = 1
                  AND JSON_TYPE(JSON_EXTRACT(texts, '$.white.text')) <> 'STRING')
              OR (JSON_CONTAINS_PATH(texts, 'one', '$.white.args') = 1
                  AND JSON_TYPE(JSON_EXTRACT(texts, '$.white.args')) <> 'OBJECT')
              OR (JSON_CONTAINS_PATH(texts, 'one', '$.grey.text') = 1
                  AND JSON_TYPE(JSON_EXTRACT(texts, '$.grey.text')) <> 'STRING')
              OR (JSON_CONTAINS_PATH(texts, 'one', '$.grey.args') = 1
                  AND JSON_TYPE(JSON_EXTRACT(texts, '$.grey.args')) <> 'OBJECT')
              OR (JSON_CONTAINS_PATH(texts, 'one', '$.special.text') = 1
                  AND JSON_TYPE(JSON_EXTRACT(texts, '$.special.text')) <> 'STRING')
              OR (JSON_CONTAINS_PATH(texts, 'one', '$.special.args') = 1
                  AND JSON_TYPE(JSON_EXTRACT(texts, '$.special.args')) <> 'OBJECT')
          )
    )
);
CALL assert_equals(
    'card texts nullability does not match consequences',
    0,
    (
        SELECT COUNT(*)
        FROM twd_card_info
        WHERE (
            consequence_black IS NULL
            AND consequence_white IS NULL
            AND consequence_grey IS NULL
            AND texts IS NOT NULL
        ) OR (
            (
                consequence_black IS NOT NULL
                OR consequence_white IS NOT NULL
                OR consequence_grey IS NOT NULL
            )
            AND texts IS NULL
        )
    )
);
CALL assert_equals(
    'card text zones do not match consequences',
    0,
    (
        SELECT COUNT(*)
        FROM twd_card_info
        WHERE (consequence_black IS NOT NULL) <>
              (JSON_CONTAINS_PATH(COALESCE(texts, JSON_OBJECT()), 'one', '$.black.text') = 1)
           OR (consequence_white IS NOT NULL) <>
              (JSON_CONTAINS_PATH(COALESCE(texts, JSON_OBJECT()), 'one', '$.white.text') = 1)
           OR (consequence_grey IS NOT NULL) <>
              (JSON_CONTAINS_PATH(COALESCE(texts, JSON_OBJECT()), 'one', '$.grey.text') = 1)
    )
);
CALL assert_equals(
    'empty card display texts',
    0,
    (
        SELECT COUNT(*)
        FROM twd_card_info
        WHERE (JSON_CONTAINS_PATH(COALESCE(texts, JSON_OBJECT()), 'one', '$.black.text') = 1
                  AND TRIM(JSON_UNQUOTE(JSON_EXTRACT(texts, '$.black.text'))) = '')
           OR (JSON_CONTAINS_PATH(COALESCE(texts, JSON_OBJECT()), 'one', '$.white.text') = 1
                  AND TRIM(JSON_UNQUOTE(JSON_EXTRACT(texts, '$.white.text'))) = '')
           OR (JSON_CONTAINS_PATH(COALESCE(texts, JSON_OBJECT()), 'one', '$.grey.text') = 1
                  AND TRIM(JSON_UNQUOTE(JSON_EXTRACT(texts, '$.grey.text'))) = '')
    )
);
CALL assert_equals(
    'Canned food display definition',
    1,
    (
        SELECT COUNT(*)
        FROM twd_card_info
        WHERE card_type = '3'
          AND card_type_arg = 7
          AND card_name = 'Canned food'
          AND JSON_LENGTH(texts) = 3
          AND JSON_LENGTH(JSON_EXTRACT(texts, '$.black')) = 1
          AND JSON_UNQUOTE(JSON_EXTRACT(texts, '$.black.text')) = 'No effect'
          AND JSON_LENGTH(JSON_EXTRACT(texts, '$.white')) = 2
          AND JSON_UNQUOTE(JSON_EXTRACT(texts, '$.white.text')) = '${consumeHunger}'
          AND JSON_UNQUOTE(JSON_EXTRACT(texts, '$.white.args.consumeHunger.type')) = 'icon'
          AND JSON_UNQUOTE(JSON_EXTRACT(texts, '$.white.args.consumeHunger.name')) = 'consumedHunger'
          AND JSON_LENGTH(JSON_EXTRACT(texts, '$.grey')) = 2
          AND JSON_UNQUOTE(JSON_EXTRACT(texts, '$.grey.text')) = '${heal} up to 2 characters'
          AND JSON_LENGTH(JSON_EXTRACT(texts, '$.grey.args')) = 1
          AND JSON_UNQUOTE(JSON_EXTRACT(texts, '$.grey.args.heal.type')) = 'icon'
          AND JSON_UNQUOTE(JSON_EXTRACT(texts, '$.grey.args.heal.name')) = 'heal'
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
